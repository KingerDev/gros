<?php

namespace App\Services;

use App\Models\User;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnalyticsService
{
    /** Základné súčty za obdobie (bez prevodov). */
    public function summary(User $user, Period $period): array
    {
        $rows = $period->apply($user->transactions()->where('type', '!=', 'transfer'))->get(['type', 'amount']);
        $income = (float) $rows->where('type', 'income')->sum('amount');
        $expense = (float) $rows->where('type', 'expense')->sum('amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savingsRate' => $income > 0 ? round(($income - $expense) / $income * 100) : 0,
            'count' => $rows->count(),
        ];
    }

    /** Súčty podľa kategórie za obdobie a typ. */
    public function byCategory(User $user, Period $period, string $type): Collection
    {
        return $period->apply($user->transactions()->where('type', $type)->whereNotNull('category_id'))
            ->selectRaw('category_id, sum(amount) as amount, count(*) as cnt')
            ->groupBy('category_id')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['category_id' => (int) $r->category_id, 'amount' => (float) $r->amount, 'count' => (int) $r->cnt]);
    }

    /** Príjmy/výdavky/netto po mesiacoch (posledných N mesiacov). */
    public function monthlySeries(User $user, int $months = 24): Collection
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()
            ->where('type', '!=', 'transfer')
            ->where('date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, type, sum(amount) as amount")
            ->groupBy('ym', 'type')
            ->get();

        $out = collect();
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $ym = $m->format('Y-m');
            $income = (float) $rows->where('ym', $ym)->where('type', 'income')->sum('amount');
            $expense = (float) $rows->where('ym', $ym)->where('type', 'expense')->sum('amount');
            $out->push([
                'ym' => $ym,
                'label' => $this->shortMonth($m),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ]);
        }

        return $out;
    }

    /** Mesačný vývoj jednej kategórie + jej top transakcie. */
    public function categoryDetail(User $user, int $categoryId, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()
            ->where('category_id', $categoryId)
            ->where('date', '>=', $start->toDateString())
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as ym, sum(amount) as amount, count(*) as cnt")
            ->groupBy('ym')
            ->get();

        $monthly = collect();
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $ym = $m->format('Y-m');
            $monthly->push(['label' => $this->shortMonth($m), 'amount' => (float) ($rows->firstWhere('ym', $ym)->amount ?? 0)]);
        }

        $all = $user->transactions()->where('category_id', $categoryId)->get(['amount', 'note', 'date', 'type']);
        $top = $all->sortByDesc('amount')->take(6)->map(fn ($t) => [
            'amount' => (float) $t->amount,
            'note' => $t->note,
            'date' => $t->date->toDateString(),
        ])->values();

        return [
            'monthly' => $monthly,
            'top' => $top,
            'total' => (float) $all->sum('amount'),
            'count' => $all->count(),
            'avg' => $all->count() ? (float) $all->avg('amount') : 0,
        ];
    }

    /** Top obchodníci/miesta z poznámok (výdavky) za obdobie. */
    public function topMerchants(User $user, Period $period, int $limit = 12): Collection
    {
        return $period->apply($user->transactions()->where('type', 'expense')->whereNotNull('note')->where('note', '!=', ''))
            ->selectRaw('TRIM(note) as merchant, sum(amount) as amount, count(*) as cnt')
            ->groupBy('merchant')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['merchant' => $r->merchant, 'amount' => (float) $r->amount, 'count' => (int) $r->cnt]);
    }

    /** „Mesiac v kocke" — súhrn posledného ukončeného mesiaca vs. mesiac predtým. */
    public function monthReport(User $user): ?array
    {
        $month = CarbonImmutable::today()->subMonthsNoOverflow(1)->startOfMonth();
        $prev = $month->subMonthsNoOverflow(1);

        $cur = $this->monthTotals($user, $month);
        if ($cur['count'] === 0) {
            return null;
        }
        $before = $this->monthTotals($user, $prev);

        $range = [$month->toDateString(), $month->endOfMonth()->toDateString()];

        $top = $user->transactions()->where('type', 'expense')->whereNotNull('category_id')
            ->whereBetween('date', $range)
            ->selectRaw('category_id, SUM(amount) as amount')
            ->groupBy('category_id')->orderByDesc('amount')->first();

        $big = $user->transactions()->where('type', 'expense')
            ->whereBetween('date', $range)
            ->orderByDesc('amount')->first();

        $pct = fn (float $now, float $base) => $base > 0 ? round(($now - $base) / $base * 100, 1) : null;

        return [
            'label' => Period::monthLabel($month),
            'prevLabel' => Period::monthLabel($prev),
            'income' => $cur['income'],
            'expense' => $cur['expense'],
            'net' => $cur['net'],
            'rate' => $cur['rate'],
            'count' => $cur['count'],
            'incomeDeltaPct' => $pct($cur['income'], $before['income']),
            'expenseDeltaPct' => $pct($cur['expense'], $before['expense']),
            'topCategory' => $top ? ['category_id' => (int) $top->category_id, 'amount' => (float) $top->amount] : null,
            'biggestExpense' => $big ? [
                'note' => $big->note,
                'category_id' => $big->category_id,
                'amount' => (float) $big->amount,
                'date' => $big->date->toDateString(),
            ] : null,
        ];
    }

    /** @return array{income: float, expense: float, net: float, rate: int, count: int} */
    protected function monthTotals(User $user, CarbonImmutable $month): array
    {
        $rows = $user->transactions()->where('type', '!=', 'transfer')
            ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->get(['type', 'amount']);

        $income = (float) $rows->where('type', 'income')->sum('amount');
        $expense = (float) $rows->where('type', 'expense')->sum('amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'rate' => $income > 0 ? (int) round(($income - $expense) / $income * 100) : 0,
            'count' => $rows->count(),
        ];
    }

    /**
     * Fixné vs. voľné výdavky po mesiacoch. „Fixné" = opakujúce sa platby:
     * rovnaká poznámka (znormalizovaná) aspoň v 3 rôznych mesiacoch —
     * typicky nájom, energie, predplatné, splátky.
     */
    public function fixedVsVariable(User $user, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()
            ->where('type', 'expense')
            ->where('date', '>=', $start->toDateString())
            ->get(['amount', 'note', 'date'])
            ->map(fn ($t) => [
                'ym' => $t->date->format('Y-m'),
                'key' => mb_strtolower(trim((string) $t->note)),
                'amount' => (float) $t->amount,
            ]);

        $recurring = $rows->filter(fn ($r) => $r['key'] !== '')
            ->groupBy('key')
            ->filter(fn ($g) => $g->pluck('ym')->unique()->count() >= 3)
            ->keys()
            ->all();
        $recurring = array_flip($recurring);

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $ym = $m->format('Y-m');
            $monthRows = $rows->where('ym', $ym);
            $total = (float) $monthRows->sum('amount');
            $fixed = (float) $monthRows->filter(fn ($r) => isset($recurring[$r['key']]))->sum('amount');
            $series[] = [
                'ym' => $ym,
                'label' => $this->shortMonth($m),
                'fixed' => round($fixed, 2),
                'variable' => round($total - $fixed, 2),
                'share' => $total > 0 ? (int) round($fixed / $total * 100) : 0,
            ];
        }

        return ['series' => $series, 'recurringCount' => count($recurring)];
    }

    /** Automatické postrehy — počítané voči poslednému mesiacu s dátami. */
    public function insights(User $user): array
    {
        $out = [];
        $lastDate = $user->transactions()->where('type', '!=', 'transfer')->max('date');
        if (! $lastDate) {
            return $out;
        }
        $last = CarbonImmutable::parse($lastDate)->startOfMonth();

        // mesačné výdavky za posledné 4 mesiace
        $exp = [];
        for ($i = 0; $i < 4; $i++) {
            $m = $last->subMonths($i);
            $exp[$m->format('Y-m')] = (float) $user->transactions()->where('type', 'expense')
                ->whereBetween('date', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])->sum('amount');
        }
        $curKey = $last->format('Y-m');
        $cur = $exp[$curKey] ?? 0;
        $prev3 = collect($exp)->except($curKey);
        $avg = $prev3->count() ? $prev3->avg() : 0;
        $monthName = Period::monthLabel($last);

        if ($avg > 0 && $cur > 0) {
            $diff = ($cur - $avg) / $avg * 100;
            if (abs($diff) >= 10) {
                $out[] = [
                    'tone' => $diff > 0 ? 'warn' : 'good',
                    'text' => $diff > 0
                        ? "V {$monthName} si minul o ".round($diff)." % viac než býva priemer."
                        : "V {$monthName} si minul o ".round(abs($diff)).' % menej než býva priemer. 👏',
                ];
            }
        }

        // najväčšia kategória posledného mesiaca + trend
        $catRow = $user->transactions()->where('type', 'expense')->whereNotNull('category_id')
            ->whereBetween('date', [$last->startOfMonth()->toDateString(), $last->endOfMonth()->toDateString()])
            ->selectRaw('category_id, sum(amount) as a')->groupBy('category_id')->orderByDesc('a')->first();
        if ($catRow) {
            $cat = \App\Models\Category::find($catRow->category_id);
            if ($cat) {
                $out[] = ['tone' => 'info', 'text' => "Najviac v {$monthName} išlo na „{$cat->name}\" — ".$this->eur($catRow->a).'.'];
            }
        }

        // najväčší jednotlivý výdavok posledného mesiaca
        $big = $user->transactions()->where('type', 'expense')
            ->whereBetween('date', [$last->startOfMonth()->toDateString(), $last->endOfMonth()->toDateString()])
            ->orderByDesc('amount')->first();
        if ($big) {
            $title = $big->note ?: ($big->category?->name ?? 'výdavok');
            $out[] = ['tone' => 'info', 'text' => "Najväčší výdavok v {$monthName}: „{$title}\" ".$this->eur($big->amount).'.'];
        }

        // miera úspor za posledný mesiac
        $inc = (float) $user->transactions()->where('type', 'income')
            ->whereBetween('date', [$last->startOfMonth()->toDateString(), $last->endOfMonth()->toDateString()])->sum('amount');
        if ($inc > 0) {
            $rate = round(($inc - $cur) / $inc * 100);
            $out[] = [
                'tone' => $rate >= 0 ? 'good' : 'warn',
                'text' => "Miera úspor v {$monthName}: {$rate} %.",
            ];
        }

        return $out;
    }

    protected function shortMonth(CarbonImmutable $d): string
    {
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'][$d->month].' '.substr((string) $d->year, 2);
    }

    protected function eur(float $n): string
    {
        return number_format($n, 2, ',', ' ').' €';
    }

    /** Najstaršia a najnovšia transakcia (na medze prepínača období). */
    public function dataRange(User $user): array
    {
        return [
            'min' => $user->transactions()->min('date'),
            'max' => $user->transactions()->max('date'),
        ];
    }
}
