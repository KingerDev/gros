<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnalyticsService
{
    public function __construct(protected ExpenseClassifier $classifier) {}

    /** Základné súčty za obdobie (bez prevodov). Výdavky sú čisté — po odrátaní vrátení. */
    public function summary(User $user, Period $period): array
    {
        // presuny do portfólia nie sú spotreba — do výdavkov ani do miery úspor nepatria
        $rows = $period->apply($this->classifier->excludeSavings(
            $user->transactions()->analyzed()->where('type', '!=', 'transfer'), $user
        ))->get(['type', 'amount', 'refunded_amount']);
        $income = (float) $rows->where('type', 'income')->sum('amount');
        $expense = (float) $rows->where('type', 'expense')->sum('net_amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savingsRate' => $income > 0 ? round(($income - $expense) / $income * 100) : 0,
            'count' => $rows->count(),
        ];
    }

    /**
     * Súčty podľa kategórie za obdobie a typ, zrolované do skupín — rovnako,
     * ako to počíta rozpočet na skupinu. Podkategórie sú v `children`, aby sa
     * dal rozklad rozbaliť.
     */
    public function byCategory(User $user, Period $period, string $type): Collection
    {
        $rows = $period->apply($this->classifier->excludeSavings(
            $user->transactions()->analyzed()->where('type', $type)->whereNotNull('category_id'), $user
        ))
            ->selectRaw('category_id, '.Transaction::netSum('amount').', count(*) as cnt')
            ->groupBy('category_id')
            ->orderByDesc('amount')
            ->get();

        $parentOf = $user->categories()->pluck('parent_id', 'id');

        $groups = [];
        foreach ($rows as $r) {
            $catId = (int) $r->category_id;
            $groupId = (int) ($parentOf[$catId] ?? $catId);
            $leaf = ['category_id' => $catId, 'amount' => (float) $r->amount, 'count' => (int) $r->cnt];

            $groups[$groupId] ??= ['category_id' => $groupId, 'amount' => 0.0, 'count' => 0, 'children' => []];
            $groups[$groupId]['amount'] += $leaf['amount'];
            $groups[$groupId]['count'] += $leaf['count'];
            $groups[$groupId]['children'][] = $leaf;
        }

        return collect($groups)
            ->map(function (array $g) {
                // Rozklad má zmysel len tam, kde je z čoho vyberať
                $onlySelf = count($g['children']) === 1 && $g['children'][0]['category_id'] === $g['category_id'];
                $g['children'] = $onlySelf ? [] : collect($g['children'])->sortByDesc('amount')->values()->all();
                $g['amount'] = round($g['amount'], 2);

                return $g;
            })
            ->sortByDesc('amount')
            ->values();
    }

    /** Príjmy/výdavky/netto po mesiacoch (posledných N mesiacov). */
    public function monthlySeries(User $user, int $months = 24): Collection
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()->analyzed()
            ->where('type', '!=', 'transfer')
            ->where('date', '>=', $start->toDateString())
            ->selectRaw(Transaction::yearMonth().' as ym, type, '.Transaction::netSum('amount'))
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

    /**
     * Detail kategórie: mesačný vývoj (kontext okolo obdobia) a súčty
     * s transakciami za zvolené obdobie — tie isté čísla, aké ukazuje graf.
     * Pri skupine zahŕňa aj jej podkategórie, rovnako ako rozpočet na skupinu.
     */
    public function categoryDetail(User $user, int $categoryId, Period $period, int $months = 12): array
    {
        $catIds = collect([$categoryId])
            ->merge($user->categories()->where('parent_id', $categoryId)->pluck('id'))
            ->all();

        // Trend končí posledným mesiacom obdobia, aby zvolený mesiac bolo v grafe vidieť
        $anchor = ($period->to ?? CarbonImmutable::today())->startOfMonth();
        $start = $anchor->subMonths($months - 1);

        $rows = $user->transactions()->analyzed()
            ->whereIn('category_id', $catIds)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<=', $anchor->endOfMonth()->toDateString())
            ->selectRaw(Transaction::yearMonth().' as ym, '.Transaction::netSum('amount').', count(*) as cnt')
            ->groupBy('ym')
            ->get();

        $monthly = collect();
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $ym = $m->format('Y-m');
            $monthly->push([
                'label' => $this->shortMonth($m),
                'amount' => (float) ($rows->firstWhere('ym', $ym)->amount ?? 0),
                'current' => $period->from && $ym >= $period->from->format('Y-m') && $ym <= $anchor->format('Y-m'),
            ]);
        }

        $all = $period->apply($user->transactions()->analyzed()->whereIn('category_id', $catIds))
            ->get(['id', 'category_id', 'amount', 'refunded_amount', 'note', 'date', 'type']);

        $top = $all->sortByDesc('net_amount')->values()->take(50)->map(fn ($t) => [
            'id' => $t->id,
            'category_id' => $t->category_id,
            'amount' => $t->net_amount,
            'note' => $t->note,
            'date' => $t->date->toDateString(),
        ])->values();

        return [
            'monthly' => $monthly,
            'top' => $top,
            'total' => (float) $all->sum('net_amount'),
            'count' => $all->count(),
            'avg' => $all->count() ? (float) $all->avg('net_amount') : 0,
            'periodLabel' => $period->label,
            'truncated' => $all->count() > $top->count(),
            'isGroup' => count($catIds) > 1,
        ];
    }

    /** Top obchodníci/miesta z poznámok (výdavky) za obdobie. */
    public function topMerchants(User $user, Period $period, int $limit = 12): Collection
    {
        return $period->apply($user->transactions()->analyzed()->where('type', 'expense')->whereNotNull('note')->where('note', '!=', ''))
            ->selectRaw('TRIM(note) as merchant, '.Transaction::netSum('amount').', count(*) as cnt')
            ->groupBy('merchant')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['merchant' => $r->merchant, 'amount' => (float) $r->amount, 'count' => (int) $r->cnt]);
    }

    /**
     * „V kocke" — uzavretý súhrn odvodený od zvoleného obdobia, porovnaný
     * s obdobím pred ním. Vracia null, ak v ňom nie sú žiadne transakcie.
     */
    public function periodReport(User $user, Period $period): ?array
    {
        [$heading, $target] = $this->reportTarget($period);

        $cur = $this->rangeTotals($user, $target);
        if ($cur['count'] === 0) {
            return null;
        }
        $before = ($prev = $target->previous()) ? $this->rangeTotals($user, $prev) : null;

        $top = $target->apply($user->transactions()->analyzed()->where('type', 'expense')->whereNotNull('category_id'))
            ->selectRaw('category_id, '.Transaction::netSum('amount'))
            ->groupBy('category_id')->orderByDesc('amount')->first();

        $big = $target->apply($user->transactions()->analyzed()->where('type', 'expense'))
            ->orderByDesc(Transaction::netExpression())->first();

        $pct = fn (float $now, ?float $base) => $base !== null && $base > 0 ? round(($now - $base) / $base * 100, 1) : null;

        return [
            // Pri kĺzavých rozsahoch je obdobie už v nadpise („Posledných 30 dní v kocke")
            'title' => $heading.($target->key === '30d' ? '' : ' · '.$target->label),
            'prevLabel' => $prev?->label,
            'income' => $cur['income'],
            'expense' => $cur['expense'],
            'net' => $cur['net'],
            'rate' => $cur['rate'],
            'count' => $cur['count'],
            'incomeDeltaPct' => $pct($cur['income'], $before['income'] ?? null),
            'expenseDeltaPct' => $pct($cur['expense'], $before['expense'] ?? null),
            'topCategory' => $top ? ['category_id' => (int) $top->category_id, 'amount' => (float) $top->amount] : null,
            'biggestExpense' => $big ? [
                'note' => $big->note,
                'category_id' => $big->category_id,
                'amount' => $big->net_amount,
                'date' => $big->date->toDateString(),
            ] : null,
        ];
    }

    /**
     * Ktoré obdobie sa má zhrnúť podľa zvoleného filtra. Pri mesiaci a roku sa
     * ukazuje to predchádzajúce — bežiace obdobie je neúplné a porovnanie by
     * klamalo. Kĺzavé rozsahy (30 dní, vlastný) sú už uzavreté, tie berieme tak, ako sú.
     *
     * @return array{0: string, 1: Period}
     */
    protected function reportTarget(Period $period): array
    {
        return match ($period->key) {
            'month' => ['Mesiac v kocke', $period->previous() ?? $this->lastClosedMonth()],
            'year' => ['Rok v kocke', $period->previous() ?? $this->lastClosedMonth()],
            '30d' => ['Posledných 30 dní v kocke', $period],
            'custom' => ['Obdobie v kocke', $period],
            default => ['Mesiac v kocke', $this->lastClosedMonth()],
        };
    }

    /** Posledný ukončený mesiac — záloha pre „Celé obdobie", kde niet čo posunúť. */
    protected function lastClosedMonth(): Period
    {
        $m = CarbonImmutable::today()->subMonthsNoOverflow(1)->startOfMonth();

        return new Period('month', $m, $m->endOfMonth(), Period::monthLabel($m), $m->format('Y-m'));
    }

    /** @return array{income: float, expense: float, net: float, rate: int, count: int} */
    protected function rangeTotals(User $user, Period $period): array
    {
        $rows = $period->apply($user->transactions()->analyzed()->where('type', '!=', 'transfer'))
            ->get(['type', 'amount', 'refunded_amount']);

        $income = (float) $rows->where('type', 'income')->sum('amount');
        $expense = (float) $rows->where('type', 'expense')->sum('net_amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'rate' => $income > 0 ? (int) round(($income - $expense) / $income * 100) : 0,
            'count' => $rows->count(),
        ];
    }

    /**
     * Fixné vs. voľné výdavky po mesiacoch. Za fixné považujeme:
     *  a) splátky úverov/lízingov a predplatné — tie appka generuje sama,
     *     takže o ich záväznosti vieme naisto už od prvej splátky;
     *  b) ručne zapísané platby s rovnakou poznámkou aspoň v 3 rôznych
     *     mesiacoch — nájom, energie a podobne, kde iný signál nemáme.
     */
    public function fixedVsVariable(User $user, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->where('date', '>=', $start->toDateString())
            ->get(['amount', 'refunded_amount', 'note', 'date', 'source'])
            ->map(fn ($t) => [
                'ym' => $t->date->format('Y-m'),
                'key' => mb_strtolower(trim((string) $t->note)),
                'amount' => $t->net_amount,
                'committed' => in_array($t->source, ['loan', 'subscription'], true),
            ]);

        $recurring = $rows->filter(fn ($r) => $r['key'] !== '')
            ->groupBy('key')
            ->filter(fn ($g) => $g->pluck('ym')->unique()->count() >= 3)
            ->keys()
            ->all();
        $recurring = array_flip($recurring);

        $isFixed = fn ($r) => $r['committed'] || isset($recurring[$r['key']]);

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $ym = $m->format('Y-m');
            $monthRows = $rows->where('ym', $ym);
            $total = (float) $monthRows->sum('amount');
            $fixed = (float) $monthRows->filter($isFixed)->sum('amount');
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

    /**
     * Automatické postrehy o zvolenom období. Label je pred textom oddelený „·",
     * lebo skloňovať sa dá „August 2026" aj „Posledných 30 dní" len ťažko.
     */
    public function insights(User $user, Period $period): array
    {
        $out = [];
        $cur = $this->rangeTotals($user, $period);
        if ($cur['count'] === 0) {
            return $out;
        }

        $label = $period->label;

        // Priemer výdavkov za tri predchádzajúce rovnako dlhé obdobia
        $before = [];
        $p = $period;
        while (count($before) < 3 && ($p = $p->previous())) {
            $before[] = $this->rangeTotals($user, $p)['expense'];
        }
        $avg = $before ? array_sum($before) / count($before) : 0;

        if ($avg > 0 && $cur['expense'] > 0) {
            $diff = ($cur['expense'] - $avg) / $avg * 100;
            if (abs($diff) >= 10) {
                $out[] = [
                    'tone' => $diff > 0 ? 'warn' : 'good',
                    'text' => $diff > 0
                        ? "{$label} · minul si o ".round($diff).' % viac než býva priemer.'
                        : "{$label} · minul si o ".round(abs($diff)).' % menej než býva priemer. 👏',
                ];
            }
        }

        // Najväčšia kategória obdobia
        $catRow = $period->apply($user->transactions()->analyzed()->where('type', 'expense')->whereNotNull('category_id'))
            ->selectRaw('category_id, '.Transaction::netSum('a'))->groupBy('category_id')->orderByDesc('a')->first();
        if ($catRow && ($cat = Category::find($catRow->category_id))) {
            $out[] = ['tone' => 'info', 'text' => "{$label} · najviac išlo na „{$cat->name}\" — ".$this->eur($catRow->a).'.'];
        }

        // Najväčší jednotlivý výdavok obdobia
        $big = $period->apply($user->transactions()->analyzed()->where('type', 'expense'))
            ->orderByDesc(Transaction::netExpression())->first();
        if ($big) {
            $title = $big->note ?: ($big->category?->name ?? 'výdavok');
            $out[] = ['tone' => 'info', 'text' => "{$label} · najväčší výdavok: „{$title}\" ".$this->eur($big->net_amount).'.'];
        }

        if ($cur['income'] > 0) {
            $out[] = [
                'tone' => $cur['rate'] >= 0 ? 'good' : 'warn',
                'text' => "{$label} · miera úspor {$cur['rate']} %.",
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
