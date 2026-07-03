<?php

namespace App\Services;

use App\Models\Investment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Rekonštruuje hodnotu portfólia po mesiacoch z jednotlivých nákupov/predajov
 * (lots) + historických mesačných cien (Yahoo, USD→EUR cez Frankfurter).
 * Výsledok je cache-ovaný (invaliduje sa pri zmene lots).
 */
class PortfolioHistoryService
{
    public function monthlySeries(User $user): array
    {
        $holdings = $user->investments()->with('lots')->get()
            ->filter(fn ($i) => $i->lots->isNotEmpty());

        if ($holdings->isEmpty()) {
            return ['series' => [], 'current' => ['value' => 0, 'invested' => 0, 'gain' => 0, 'pct' => 0]];
        }

        $sig = md5($holdings->pluck('lots')->flatten()->map(fn ($l) => "{$l->id}:{$l->type}:{$l->units}:{$l->price}:{$l->date}")->implode('|')
            .'|'.$holdings->map(fn ($i) => $i->id.$i->current_price)->implode(',')
            .'|'.CarbonImmutable::today()->format('Y-m-d'));

        return Cache::remember("portfolio_history:{$user->id}:$sig", now()->addHours(6), fn () => $this->build($holdings));
    }

    /** @param \Illuminate\Support\Collection<int, Investment> $holdings */
    protected function build($holdings): array
    {
        $start = CarbonImmutable::parse($holdings->pluck('lots')->flatten()->min('date'))->startOfMonth();
        $end = CarbonImmutable::today()->startOfMonth();
        $currentYm = $end->format('Y-m');

        // mesiace v rozsahu
        $months = [];
        for ($m = $start; $m <= $end; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        // ceny za kus (EUR) po mesiacoch pre každú pozíciu
        $priceMaps = [];
        $needFx = [];
        $native = [];
        foreach ($holdings as $inv) {
            [$map, $currency] = $this->yahooMonthly($inv, $start);
            $native[$inv->id] = ['map' => $map, 'currency' => $currency];
            if ($currency && $currency !== 'EUR') {
                $needFx[$currency] = true;
            }
        }

        // FX časové rady (EUR → mena) po mesiacoch
        $fx = [];
        foreach (array_keys($needFx) as $cur) {
            $fx[$cur] = $this->fxMonthly($cur, $start);
        }

        // prepočet na EUR + carry-forward, current mesiac = živá cena
        foreach ($holdings as $inv) {
            $n = $native[$inv->id];
            $eur = [];
            $lastRate = null;
            foreach ($months as $ym) {
                $close = $n['map'][$ym] ?? null;
                if ($close === null) {
                    continue;
                }
                if ($n['currency'] && $n['currency'] !== 'EUR') {
                    $lastRate = $fx[$n['currency']][$ym] ?? $lastRate;
                    if ($lastRate) {
                        $eur[$ym] = $close / $lastRate;
                    }
                } else {
                    $eur[$ym] = $close;
                }
            }
            $eur[$currentYm] = (float) $inv->current_price; // najnovší bod = živá cena
            $priceMaps[$inv->id] = $eur;
        }

        // séria: hodnota + vklad po mesiacoch
        $series = [];
        $lastPrice = [];
        foreach ($months as $ym) {
            $monthEnd = CarbonImmutable::parse($ym.'-01')->endOfMonth();
            $value = 0.0;
            $invested = 0.0;
            foreach ($holdings as $inv) {
                [$units, $avg] = $this->positionAt($inv, $monthEnd);
                if ($units <= 1e-9) {
                    continue;
                }
                $price = $priceMaps[$inv->id][$ym] ?? ($lastPrice[$inv->id] ?? (float) $inv->current_price);
                $lastPrice[$inv->id] = $price;
                $value += $units * $price;
                $invested += $units * $avg;
            }
            $series[] = [
                'ym' => $ym,
                'label' => $this->label($ym),
                'value' => round($value, 2),
                'invested' => round($invested, 2),
            ];
        }

        $last = end($series) ?: ['value' => 0, 'invested' => 0];

        return [
            'series' => $series,
            'current' => [
                'value' => $last['value'],
                'invested' => $last['invested'],
                'gain' => round($last['value'] - $last['invested'], 2),
                'pct' => $last['invested'] > 0 ? round(($last['value'] - $last['invested']) / $last['invested'] * 100, 1) : 0,
            ],
        ];
    }

    /** Units + priemerná cena pozície k dátumu (average-cost). */
    protected function positionAt(Investment $inv, CarbonImmutable $date): array
    {
        $units = 0.0;
        $avg = 0.0;
        foreach ($inv->lots as $lot) {
            if ($lot->date->gt($date)) {
                continue;
            }
            $u = (float) $lot->units;
            $p = (float) $lot->price;
            if ($lot->type === 'buy') {
                $nu = $units + $u;
                $avg = $nu > 0 ? ($units * $avg + $u * $p) / $nu : 0;
                $units = $nu;
            } else {
                $units = max(0, $units - $u);
            }
        }

        return [$units, $avg];
    }

    /** @return array{0: array<string,float>, 1: ?string} ym→close, currency */
    protected function yahooMonthly(Investment $inv, CarbonImmutable $start): array
    {
        $symbol = $inv->quote_source === 'yahoo' && $inv->quote_symbol
            ? $inv->quote_symbol
            : ($inv->kind === 'crypto' ? strtoupper($inv->ticker).'-EUR' : null);

        if (! $symbol) {
            return [[], 'EUR'];
        }

        try {
            $res = Http::timeout(20)->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($symbol), [
                    'period1' => $start->startOfMonth()->timestamp,
                    'period2' => CarbonImmutable::today()->addDay()->timestamp,
                    'interval' => '1mo',
                ]);
            if (! $res->ok()) {
                return [[], 'EUR'];
            }
            $r = data_get($res->json(), 'chart.result.0');
            $ts = data_get($r, 'timestamp', []);
            $cl = data_get($r, 'indicators.quote.0.close', []);
            $map = [];
            foreach ($ts as $i => $t) {
                $c = $cl[$i] ?? null;
                if ($c !== null) {
                    $map[CarbonImmutable::createFromTimestamp($t)->format('Y-m')] = (float) $c;
                }
            }

            return [$map, data_get($r, 'meta.currency', 'EUR')];
        } catch (\Throwable) {
            return [[], 'EUR'];
        }
    }

    /** EUR→mena kurz po mesiacoch (posledný deň mesiaca). */
    protected function fxMonthly(string $currency, CarbonImmutable $start): array
    {
        try {
            $res = Http::timeout(20)->get(
                'https://api.frankfurter.dev/v1/'.$start->format('Y-m-d').'..'.CarbonImmutable::today()->format('Y-m-d'),
                ['base' => 'EUR', 'symbols' => $currency]
            );
            $rates = data_get($res->json(), 'rates', []);
            $byMonth = [];
            foreach ($rates as $date => $vals) {
                $ym = substr($date, 0, 7);
                $byMonth[$ym] = (float) ($vals[$currency] ?? 0); // posledný záznam mesiaca prepíše
            }

            return $byMonth;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function label(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        $short = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

        return $short[(int) $m].' '.substr($y, 2);
    }
}
