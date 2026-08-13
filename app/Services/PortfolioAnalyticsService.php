<?php

namespace App\Services;

use App\Models\InvestmentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Analýza portfólia, ktorú jednoduché „(hodnota − vklad) / vklad" nedá:
 *  - XIRR (peniazmi vážený ročný výnos) — jediné poctivé číslo pri postupnom investovaní
 *  - TWR / volatilita / maximálny prepad z rekonštruovaného vývoja
 *  - koncentrácia portfólia a rozloženie podľa druhu aktíva
 *  - porovnanie s benchmarkom: „čo keby tie isté peniaze išli do svetového ETF"
 */
class PortfolioAnalyticsService
{
    public function __construct(
        protected PortfolioHistoryService $history,
        protected MarketDataService $market,
        protected ExpenseClassifier $classifier,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $investments = $user->investments()->with('lots')->get();
        $withLots = $investments->filter(fn ($i) => $i->lots->isNotEmpty());

        if ($withLots->isEmpty()) {
            return ['ok' => false, 'reason' => 'Zatiaľ žiadne nákupy — analýza sa zobrazí po pridaní prvej transakcie.'];
        }

        $sig = md5($withLots->pluck('lots')->flatten()->map(fn ($l) => "{$l->id}:{$l->type}:{$l->units}:{$l->price}:{$l->date}")->implode('|')
            .'|'.$investments->map(fn ($i) => $i->id.':'.$i->current_price)->implode(',')
            .'|'.CarbonImmutable::today()->format('Y-m-d'));

        return Cache::remember("portfolio_analytics:{$user->id}:$sig", now()->addHours(6), function () use ($user, $investments, $withLots) {
            $value = 0.0;
            $cost = 0.0;
            $realized = 0.0;
            foreach ($investments as $i) {
                $value += $i->value;
                $cost += $i->cost;
                $realized += $i->realized_gain;
            }

            // --- peňažné toky (nákup = −, predaj = +) ---
            $flows = [];
            foreach ($withLots as $inv) {
                foreach ($inv->lots as $lot) {
                    $amount = (float) $lot->units * (float) $lot->price;
                    $flows[] = [
                        'date' => CarbonImmutable::parse($lot->date->toDateString()),
                        'amount' => $lot->type === 'buy' ? -$amount : $amount,
                    ];
                }
            }
            usort($flows, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);

            $xirrFlows = [...$flows, ['date' => CarbonImmutable::today(), 'amount' => $value]];
            $xirr = $this->xirr($xirrFlows);

            $invested = 0.0;   // celkovo vložené (súčet nákupov)
            $withdrawn = 0.0;  // celkovo vybraté (súčet predajov)
            foreach ($flows as $f) {
                if ($f['amount'] < 0) {
                    $invested += -$f['amount'];
                } else {
                    $withdrawn += $f['amount'];
                }
            }

            $first = $flows[0]['date'];
            $yearsInvesting = max(0.08, (float) $first->diffInYears(CarbonImmutable::today()));

            // --- vývoj v čase → TWR, volatilita, prepad ---
            $series = $this->history->monthlySeries($user)['series'] ?? [];
            $risk = $this->riskMetrics($series);

            // --- rozloženie a koncentrácia ---
            $positions = $investments->filter(fn ($i) => $i->value > 0);
            $totalValue = max(1e-9, $positions->sum(fn ($i) => $i->value));

            $byKind = [];
            foreach ($positions as $i) {
                $byKind[$i->kind] = ($byKind[$i->kind] ?? 0) + $i->value;
            }
            arsort($byKind);
            $kindRows = [];
            foreach ($byKind as $kind => $v) {
                $kindRows[] = ['kind' => $kind, 'value' => round($v, 2), 'pct' => round($v / $totalValue * 100, 1)];
            }

            $weights = $positions->map(fn ($i) => $i->value / $totalValue)->values()->all();
            $hhi = 0.0;
            foreach ($weights as $w) {
                $hhi += $w * $w;
            }
            $effective = $hhi > 0 ? 1 / $hhi : 0; // efektívny počet pozícií

            // --- porovnanie s benchmarkom ---
            $benchmark = $this->benchmarkComparison($flows, $value);

            return [
                'ok' => true,
                'value' => round($value, 2),
                'cost' => round($cost, 2),
                'invested' => round($invested, 2),
                'withdrawn' => round($withdrawn, 2),
                'unrealized' => round($value - $cost, 2),
                'realized' => round($realized, 2),
                'profit_total' => round($value - $cost + $realized, 2),
                'xirr' => $xirr === null ? null : round($xirr * 100, 2),
                'simple_pct' => $cost > 0 ? round(($value - $cost) / $cost * 100, 1) : 0,
                'years_investing' => round($yearsInvesting, 1),
                'first_purchase' => $first->toDateString(),
                'avg_monthly_contribution' => round($invested / max(1, $yearsInvesting * 12), 2),
                'risk' => $risk,
                'allocation' => [
                    'by_kind' => $kindRows,
                    'positions' => $positions->count(),
                    'effective_positions' => round($effective, 1),
                    'top_weight' => $weights ? round(max($weights) * 100, 1) : 0,
                    'hhi' => round($hhi, 3),
                ],
                'benchmark' => $benchmark,
                'contribution_split' => [
                    'contributed' => round($cost, 2),
                    'growth' => round($value - $cost, 2),
                    'growth_pct' => $value > 0 ? round(max(0, $value - $cost) / $value * 100, 1) : 0,
                ],
            ];
        });
    }

    /**
     * Koľko naozaj mesačne posielaš do portfólia — merané z nákupov, nie
     * z toho, čo si niekde nastavil.
     *
     * Priemer je tu zradný: jeden väčší jednorazový vklad ho vytiahne na
     * niekoľkonásobok bežného mesiaca a projekcia by potom počítala s tempom,
     * ktoré nedržíš. Preto sa ako odhad opakujúceho sa vkladu berie medián
     * kalendárnych mesiacov vrátane tých, v ktorých si neinvestoval.
     *
     * @return array<string, mixed>
     */
    public function investmentContributions(User $user, int $months = 12): array
    {
        $lots = InvestmentTransaction::query()
            ->whereIn('investment_id', $user->investments()->select('id'))
            ->orderBy('date')
            ->get(['type', 'units', 'price', 'date']);

        if ($lots->isEmpty()) {
            return ['has_data' => false, 'months' => $months, 'series' => [], 'recommended' => 0.0];
        }

        $today = CarbonImmutable::today();
        $firstPurchase = CarbonImmutable::parse($lots->min('date'))->startOfMonth();

        // čisté toky do portfólia po mesiacoch (predaj = odliv)
        $byMonth = [];
        foreach ($lots as $lot) {
            $value = (float) $lot->units * (float) $lot->price;
            $ym = $lot->date->format('Y-m');
            $byMonth[$ym] = ($byMonth[$ym] ?? 0) + ($lot->type === 'buy' ? $value : -$value);
        }

        // celé kalendárne mesiace okna, aj tie bez pohybu — mesiac, v ktorom
        // si neinvestoval, je súčasťou tempa
        $start = $today->startOfMonth()->subMonths($months);
        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            if ($m->lt($firstPurchase)) {
                continue;
            }
            $ym = $m->format('Y-m');
            $series[] = ['ym' => $ym, 'label' => $this->history->label($ym), 'amount' => round($byMonth[$ym] ?? 0, 2)];
        }

        if (! $series) {
            return ['has_data' => false, 'months' => $months, 'series' => [], 'recommended' => 0.0];
        }

        $amounts = array_column($series, 'amount');
        $median = $this->median($amounts);
        $mean = array_sum($amounts) / count($amounts);

        // mesiace, ktoré tempo nafukujú — jednorazové vklady
        $threshold = max(3 * $median, 500.0);
        $lumps = array_values(array_filter($series, fn ($m) => $m['amount'] > $threshold));

        $recent = array_slice($amounts, -3);

        // Pri nepravidelnom investovaní môže byť medián nula — vtedy by plán
        // počítal s nulovým vkladom, hoci sa reálne investuje. Záložne sa
        // použije priemer očistený o jednorazové mesiace.
        $lumpMonths = array_column($lumps, 'ym');
        $withoutLumps = array_values(array_filter(
            $series,
            fn ($m) => ! in_array($m['ym'], $lumpMonths, true)
        ));
        $trimmedMean = $withoutLumps
            ? array_sum(array_column($withoutLumps, 'amount')) / count($withoutLumps)
            : $mean;

        $recommended = $median > 0 ? $median : max(0, $trimmedMean);

        return [
            'has_data' => true,
            'months' => count($series),
            'series' => $series,
            'first_purchase' => $firstPurchase->toDateString(),
            'total' => round(array_sum($amounts), 2),
            'mean' => round($mean, 2),
            // odhad opakujúceho sa tempa — toto ide do projekcie
            'median' => round($median, 2),
            'trimmed_mean' => round($trimmedMean, 2),
            'basis' => $median > 0 ? 'median' : 'trimmed_mean',
            'recommended' => round($recommended, 2),
            'recent3' => round(count($recent) ? array_sum($recent) / count($recent) : 0, 2),
            'lumps' => $lumps,
            'lump_total' => round(array_sum(array_column($lumps, 'amount')), 2),
            'lump_share' => array_sum($amounts) > 0
                ? round(array_sum(array_column($lumps, 'amount')) / array_sum($amounts) * 100, 1)
                : 0,
            'reconciliation' => $this->reconcile($user, $series, $start),
        ];
    }

    /**
     * Porovná dva nezávislé zdroje: nákupy zapísané v portfóliu a peniaze
     * zaúčtované ako výdavok do investícií.
     *
     * Merať sa musí z nákupov — len tie hovoria, čo naozaj vlastníš. Lenže keď
     * niečo kúpiš a nezapíšeš to, portfólio o tom nevie a projekcia počíta
     * s nižším tempom aj s nižšou hodnotou. Preto sa rozpor radšej ukáže,
     * než aby sa ticho zvolil jeden zo zdrojov.
     *
     * @param  array<int, array{ym: string, label: string, amount: float}>  $series
     * @return array<string, mixed>
     */
    protected function reconcile(User $user, array $series, CarbonImmutable $from): array
    {
        $savingsIds = $this->classifier->savingsCategoryIds($user);
        $months = array_column($series, 'ym');

        $booked = [];
        if ($savingsIds && $months) {
            $rows = $user->transactions()->analyzed()
                ->where('type', 'expense')
                ->whereIn('category_id', $savingsIds)
                ->where('date', '>=', $from->toDateString())
                ->get(['date', 'amount', 'refunded_amount']);

            foreach ($rows as $t) {
                $ym = $t->date->format('Y-m');
                if (in_array($ym, $months, true)) {
                    $booked[$ym] = ($booked[$ym] ?? 0) + (float) $t->net_amount;
                }
            }
        }

        $recordedTotal = array_sum(array_column($series, 'amount'));
        $bookedTotal = array_sum($booked);

        // pozície, do ktorých sa podľa všetkého stále prispieva, ale posledný
        // zapísaný nákup je starý — typicky sa kupuje a zabúda zapisovať
        $stale = $user->investments()->where('contributing', true)->with('lots')->get()
            ->filter(fn ($i) => (float) $i->units > 0 && $i->lots->isNotEmpty())
            ->map(function ($i) {
                $last = CarbonImmutable::parse($i->lots->max('date'));

                return [
                    'id' => $i->id,
                    'ticker' => $i->ticker,
                    'name' => $i->name,
                    'last_purchase' => $last->toDateString(),
                    'months_since' => (int) $last->diffInMonths(CarbonImmutable::today()),
                ];
            })
            ->filter(fn ($i) => $i['months_since'] >= 3)
            ->sortByDesc('months_since')
            ->values()
            ->all();

        $difference = $bookedTotal - $recordedTotal;

        return [
            'recorded' => round($recordedTotal, 2),
            'booked' => round($bookedTotal, 2),
            'difference' => round($difference, 2),
            'booked_series' => array_map(fn ($m) => ['ym' => $m, 'amount' => round($booked[$m] ?? 0, 2)], $months),
            'stale' => $stale,
            // rozpor stojí za pozretie až keď je vecný, nie pri drobnom rozdiele
            'mismatch' => $bookedTotal > 0 && abs($difference) > max(100, $bookedTotal * 0.15),
        ];
    }

    /** @param array<int, float> $values */
    protected function median(array $values): float
    {
        if (! $values) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /**
     * Rizikové ukazovatele z mesačného vývoja hodnoty. Mesačný výnos sa počíta
     * očistene o vklady/výbery (time-weighted), inak by vklad vyzeral ako zisk.
     *
     * @param  array<int, array{ym: string, value: float, invested: float}>  $series
     * @return array<string, mixed>
     */
    protected function riskMetrics(array $series): array
    {
        $returns = [];
        $labels = [];
        for ($i = 1; $i < count($series); $i++) {
            $prev = $series[$i - 1];
            $cur = $series[$i];
            if ($prev['value'] <= 1e-6) {
                continue;
            }
            $flow = $cur['invested'] - $prev['invested'];
            $r = ($cur['value'] - $flow) / $prev['value'] - 1;
            // ochrana proti artefaktom rekonštrukcie (chýbajúca historická cena)
            if ($r > 3 || $r < -0.95) {
                continue;
            }
            $returns[] = $r;
            $labels[] = $cur['ym'];
        }

        if (count($returns) < 6) {
            return ['ok' => false, 'months' => count($returns)];
        }

        // maximálny prepad na krivke hodnoty očistenej o vklady (index výkonnosti)
        $index = [1.0];
        foreach ($returns as $r) {
            $index[] = end($index) * (1 + $r);
        }
        $peak = $index[0];
        $maxDd = 0.0;
        $ddFrom = null;
        $ddTo = null;
        $peakIdx = 0;
        foreach ($index as $i => $v) {
            if ($v > $peak) {
                $peak = $v;
                $peakIdx = $i;
            }
            $dd = $peak > 0 ? $v / $peak - 1 : 0;
            if ($dd < $maxDd) {
                $maxDd = $dd;
                $ddFrom = $labels[max(0, $peakIdx - 1)] ?? null;
                $ddTo = $labels[max(0, $i - 1)] ?? null;
            }
        }

        $cagr = $this->market->cagr($returns);
        $vol = $this->market->volatility($returns);
        $positive = count(array_filter($returns, fn ($r) => $r > 0));

        return [
            'ok' => true,
            'months' => count($returns),
            'twr_cagr' => round($cagr * 100, 2),
            'volatility' => round($vol * 100, 1),
            'max_drawdown' => round($maxDd * 100, 1),
            'drawdown_from' => $ddFrom,
            'drawdown_to' => $ddTo,
            'best_month' => round(max($returns) * 100, 1),
            'worst_month' => round(min($returns) * 100, 1),
            'positive_share' => round($positive / count($returns) * 100),
            // výnos na jednotku rizika; bezriziková sadzba zanedbaná (krátke rady)
            'return_per_risk' => $vol > 0.0001 ? round($cagr / $vol, 2) : null,
        ];
    }

    /**
     * „Čo keby tie isté peniaze v tých istých dňoch išli do svetového ETF."
     *
     * @param  array<int, array{date: CarbonImmutable, amount: float}>  $flows
     * @return array<string, mixed>|null
     */
    protected function benchmarkComparison(array $flows, float $portfolioValue): ?array
    {
        if (! $flows) {
            return null;
        }

        $from = $flows[0]['date']->startOfMonth()->subMonth();
        $closes = $this->market->monthlyClosesEur('IWDA.AS', $from);
        if (count($closes) < 2) {
            return null;
        }

        $units = 0.0;
        $lastPrice = null;
        foreach ($flows as $f) {
            $ym = $f['date']->format('Y-m');
            $price = $closes[$ym] ?? $this->priceAtOrBefore($closes, $ym);
            if (! $price) {
                continue;
            }
            $lastPrice = $price;
            $units += (-$f['amount']) / $price; // nákup (záporný tok) = pridanie kusov
        }

        $current = end($closes) ?: $lastPrice;
        if (! $current || $units <= 0) {
            return null;
        }

        $value = $units * $current;
        $xirr = $this->xirr([...$flows, ['date' => CarbonImmutable::today(), 'amount' => $value]]);

        return [
            'label' => 'MSCI World (IWDA)',
            'value' => round($value, 2),
            'xirr' => $xirr === null ? null : round($xirr * 100, 2),
            'diff' => round($portfolioValue - $value, 2),
            'from' => array_key_first($closes),
        ];
    }

    /** @param array<string, float> $closes */
    protected function priceAtOrBefore(array $closes, string $ym): ?float
    {
        $prior = array_filter(array_keys($closes), fn ($k) => $k <= $ym);
        if (! $prior) {
            return null;
        }

        return $closes[max($prior)];
    }

    /**
     * XIRR — vnútorná miera výnosnosti pri nepravidelných tokoch (bisekcia,
     * spoľahlivejšia ako Newton pri divokých portfóliách).
     *
     * @param  array<int, array{date: CarbonImmutable, amount: float}>  $flows
     */
    public function xirr(array $flows): ?float
    {
        if (count($flows) < 2) {
            return null;
        }

        $hasNeg = false;
        $hasPos = false;
        foreach ($flows as $f) {
            if ($f['amount'] < 0) {
                $hasNeg = true;
            }
            if ($f['amount'] > 0) {
                $hasPos = true;
            }
        }
        if (! $hasNeg || ! $hasPos) {
            return null;
        }

        $t0 = $flows[0]['date'];
        $npv = function (float $rate) use ($flows, $t0) {
            $sum = 0.0;
            foreach ($flows as $f) {
                $years = $t0->diffInDays($f['date']) / 365.25;
                $sum += $f['amount'] / (1 + $rate) ** $years;
            }

            return $sum;
        };

        $lo = -0.9999;
        $hi = 10.0;
        $fLo = $npv($lo);
        $fHi = $npv($hi);
        if ($fLo * $fHi > 0) {
            return null;
        }

        for ($i = 0; $i < 200; $i++) {
            $mid = ($lo + $hi) / 2;
            $fMid = $npv($mid);
            if (abs($fMid) < 1e-7 || $hi - $lo < 1e-9) {
                return $mid;
            }
            if ($fLo * $fMid < 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
                $fLo = $fMid;
            }
        }

        return ($lo + $hi) / 2;
    }
}
