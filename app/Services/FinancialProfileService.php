<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Skutočný finančný obraz používateľa, meraný z transakcií — nie z toho, čo si
 * kde ručne nastavil. Toto je most medzi dennými výdavkami a dôchodkovou
 * projekciou: koľko naozaj odkladá, koľko naozaj míňa a z akého majetku štartuje.
 */
class FinancialProfileService
{
    /** Koľko ukončených mesiacov sa berie do priemerov — rovnako ako pri rezerve. */
    protected const WINDOW = 12;

    public function __construct(
        protected AnalyticsService $analytics,
        protected FinanceService $finance,
        protected ExpenseClassifier $classifier,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $months = $this->savingsAdjusted($user, 24);
        $recent = array_slice($months, -self::WINDOW);

        $income = $this->avg($recent, 'income');
        $gross = $this->avg($recent, 'gross_expense');
        $savingsFlow = $this->avg($recent, 'savings_flow');
        // spotreba = výdavky bez peňazí, ktoré len odišli do portfólia.
        // Jednorazovky aj udalosti v nej ostávajú — život ich prináša stále,
        // len nie každý mesiac tie isté.
        $consumption = $this->avg($recent, 'expense');
        $left = $income - $consumption;
        $housing = $recent ? $this->housingTotal($user, $recent) / count($recent) : 0.0;

        $portfolio = $this->finance->portfolio($user)['value'];
        $cash = $this->finance->cash($user);
        $debt = (float) $user->loans()->where('kind', 'owe')->sum('balance');

        return [
            // merané z transakcií
            'measured' => [
                'months' => count($recent),
                'window' => self::WINDOW,
                'income' => round($income, 2),
                // hrubé výdavky vrátane vkladov do portfólia
                'expense' => round($gross, 2),
                'savings_flow' => round($savingsFlow, 2),
                'consumption' => round($consumption, 2),
                // z toho bývanie — kto ho dnes neplatí, má dnešnú spotrebu podhodnotenú
                'housing' => round($housing, 2),
                // čo z príjmu ostane po spotrebe (vrátane toho, čo ide do portfólia)
                'savings' => round($left, 2),
                'savings_rate' => $income > 0 ? round($left / $income * 100, 1) : null,
                'has_data' => count($recent) > 0 && $income > 0,
            ],
            // majetok, z ktorého projekcia štartuje
            'assets' => [
                'portfolio' => round($portfolio, 2),
                'cash' => round($cash, 2),
                'debt' => round($debt, 2),
                'net_worth' => round($cash + $portfolio - $debt, 2),
            ],
            'series' => $months,
        ];
    }

    /**
     * Ukončené mesiace s výdavkami očistenými o presuny do portfólia.
     * `expense` = spotreba, `gross_expense` = všetko, čo odišlo z účtu.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function savingsAdjusted(User $user, int $window): array
    {
        $months = $this->completedMonths($user, $window);
        $flows = $this->monthlySavingsFlow($user, $window);

        foreach ($months as $i => $m) {
            $flow = $flows[$m['ym']] ?? 0.0;
            $months[$i]['gross_expense'] = $m['expense'];
            $months[$i]['gross_net'] = $m['net'];
            $months[$i]['savings_flow'] = $flow;
            $months[$i]['expense'] = max(0, $m['expense'] - $flow);
            $months[$i]['net'] = $m['income'] - $months[$i]['expense'];
        }

        return $months;
    }

    /**
     * Súčet výdavkov na bývanie (skupina „Bývanie" a jej podkategórie)
     * v daných mesiacoch.
     *
     * @param  array<int, array<string, mixed>>  $months
     */
    protected function housingTotal(User $user, array $months): float
    {
        $categories = $user->categories()->where('type', 'expense')->get(['id', 'name', 'parent_id']);
        $groupIds = $categories->filter(fn ($c) => mb_strtolower($c->name) === 'bývanie')->pluck('id');
        $ids = $categories
            ->filter(fn ($c) => $groupIds->contains($c->id) || $groupIds->contains($c->parent_id))
            ->pluck('id')
            ->all();
        if (! $ids) {
            return 0.0;
        }

        $yms = array_column($months, 'ym');

        return (float) $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->whereIn('category_id', $ids)
            ->whereDate('date', '>=', min($yms).'-01')
            ->whereDate('date', '<=', CarbonImmutable::parse(max($yms).'-01')->endOfMonth()->toDateString())
            ->get(['date', 'amount', 'refunded_amount'])
            ->filter(fn ($t) => in_array($t->date->format('Y-m'), $yms, true))
            ->sum('net_amount');
    }

    /**
     * Presuny do portfólia zaúčtované ako výdavok, po mesiacoch.
     *
     * @return array<string, float>
     */
    protected function monthlySavingsFlow(User $user, int $window): array
    {
        $savingsIds = $this->classifier->savingsCategoryIds($user);
        if (! $savingsIds) {
            return [];
        }

        $today = CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths($window);
        $to = $today->startOfMonth()->subDay();

        $out = [];
        $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->whereIn('category_id', $savingsIds)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get(['date', 'amount', 'refunded_amount'])
            ->each(function ($t) use (&$out) {
                $ym = $t->date->format('Y-m');
                $out[$ym] = ($out[$ym] ?? 0.0) + (float) $t->net_amount;
            });

        return $out;
    }

    /**
     * Mesačný vývoj miery úspor + priemery za 3/6/12 mesiacov.
     *
     * Presuny do portfólia sa od výdavkov odrátajú — odložené peniaze nie sú
     * minuté. Jednorazovky nie: aj keď sa tá istá nezopakuje, nejaká príde
     * vždy, a miera úspor bez nich by sľubovala viac, než človek naozaj odkladá.
     *
     * @return array<string, mixed>
     */
    public function savingsRate(User $user): array
    {
        $months = $this->savingsAdjusted($user, 24);

        $series = array_map(fn (array $m) => [
            'ym' => $m['ym'],
            'label' => $m['label'],
            'income' => round($m['income'], 2),
            'expense' => round($m['expense'], 2),
            'net' => round($m['net'], 2),
            'rate' => $m['income'] > 0 ? round($m['net'] / $m['income'] * 100, 1) : null,
            'savings_flow' => round($m['savings_flow'], 2),
            'gross_rate' => $m['income'] > 0 ? round($m['gross_net'] / $m['income'] * 100, 1) : null,
        ], $months);

        $windows = [];
        foreach ([3, 6, 12] as $n) {
            $slice = array_slice($months, -$n);
            $inc = $this->sum($slice, 'income');
            $windows[$n] = [
                'months' => count($slice),
                'income' => round($inc, 2),
                'expense' => round($this->sum($slice, 'expense'), 2),
                'rate' => $inc > 0 ? round($this->sum($slice, 'net') / $inc * 100, 1) : null,
                // to isté bez očistenia — na porovnanie, koľko robí rozdiel
                'gross_rate' => $inc > 0 ? round($this->sum($slice, 'gross_net') / $inc * 100, 1) : null,
                'savings_flow' => round($this->sum($slice, 'savings_flow'), 2),
            ];
        }

        $withRate = array_values(array_filter($series, fn ($m) => $m['rate'] !== null));
        $best = $withRate ? max(array_column($withRate, 'rate')) : null;
        $worst = $withRate ? min(array_column($withRate, 'rate')) : null;

        return [
            'series' => $series,
            'windows' => $windows,
            'current' => $windows[3]['rate'] ?? null,
            'best' => $best,
            'worst' => $worst,
            // porovnanie posledných 6 mesiacov s predošlými 6 — kam sa to hýbe
            'trend' => $this->trend($months),
        ];
    }

    /**
     * Miera úspor spolu s tým, čo znamená v rokoch práce — to je celý zmysel
     * tejto metriky. Predpoklady (reálny výnos, miera výberu) prichádzajú
     * z dôchodkového plánu, aby v celej appke platili tie isté čísla.
     *
     * @return array<string, mixed>
     */
    public function savingsRateReport(User $user, float $realReturnPct, float $withdrawalPct): array
    {
        $report = $this->savingsRate($user);
        $years = fn (?float $rate) => $rate === null ? null : $this->yearsToFreedom($rate, $realReturnPct, $withdrawalPct);
        $report['years_gross'] = [
            3 => $years($report['windows'][3]['gross_rate'] ?? null),
            6 => $years($report['windows'][6]['gross_rate'] ?? null),
            12 => $years($report['windows'][12]['gross_rate'] ?? null),
        ];

        $report['assumptions'] = ['real_return' => $realReturnPct, 'withdrawal' => $withdrawalPct];
        $report['years'] = [
            3 => $years($report['windows'][3]['rate'] ?? null),
            6 => $years($report['windows'][6]['rate'] ?? null),
            12 => $years($report['windows'][12]['rate'] ?? null),
        ];

        // referenčná škála — koľko rokov práce stojí každá úroveň sporenia
        $report['scale'] = array_map(
            fn (int $rate) => ['rate' => $rate, 'years' => $years((float) $rate)],
            [10, 20, 30, 40, 50, 60, 70]
        );

        return $report;
    }

    /**
     * Koľko rokov od nuly do finančnej slobody pri danej miere úspor.
     * Klasická rovnica: nasporený anuitný súčet sa musí rovnať cieľu
     * (ročné výdavky / miera výberu). Vracia null, ak sa nesporí.
     */
    public function yearsToFreedom(float $ratePct, float $realReturnPct = 5.0, float $withdrawalPct = 4.0): ?float
    {
        $s = $ratePct / 100;
        $r = $realReturnPct / 100;
        $swr = $withdrawalPct / 100;

        if ($s <= 0 || $s >= 1 || $r <= 0 || $swr <= 0) {
            return $s >= 1 ? 0.0 : null;
        }

        $years = log(1 + $r * (1 - $s) / ($s * $swr)) / log(1 + $r);

        return $years > 200 ? null : round($years, 1);
    }

    /**
     * Ukončené mesiace (bez prebiehajúceho) s aspoň nejakým pohybom.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function completedMonths(User $user, int $months): array
    {
        $currentYm = CarbonImmutable::today()->format('Y-m');

        return $this->analytics->monthlySeries($user, $months)
            ->reject(fn ($m) => $m['ym'] === $currentYm)
            ->filter(fn ($m) => $m['income'] > 0 || $m['expense'] > 0)
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function avg(array $rows, string $key): float
    {
        return $rows ? $this->sum($rows, $key) / count($rows) : 0.0;
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function sum(array $rows, string $key): float
    {
        return array_sum(array_map(fn ($r) => (float) $r[$key], $rows));
    }

    /**
     * Posun miery úspor: posledných 6 mesiacov vs. 6 pred nimi.
     *
     * @param  array<int, array<string, mixed>>  $months
     * @return array{delta: float, previous: float, current: float}|null
     */
    protected function trend(array $months): ?array
    {
        if (count($months) < 8) {
            return null;
        }

        $recent = array_slice($months, -6);
        $before = array_slice($months, -12, count($months) >= 12 ? 6 : count($months) - 6);

        $rate = function (array $rows): ?float {
            $inc = $this->sum($rows, 'income');

            return $inc > 0 ? $this->sum($rows, 'net') / $inc * 100 : null;
        };

        $now = $rate($recent);
        $then = $rate($before);
        if ($now === null || $then === null) {
            return null;
        }

        return ['current' => round($now, 1), 'previous' => round($then, 1), 'delta' => round($now - $then, 1)];
    }
}
