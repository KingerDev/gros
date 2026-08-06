<?php

namespace App\Services;

use App\Models\InvestmentTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Zdieľané finančné výpočty. Vracia surové čísla — formátovanie (€, privacy,
 * desatinné miesta) rieši frontend cez composable useMoney, aby prepínanie
 * súkromia bolo okamžité.
 */
class FinanceService
{
    /** Hodnota, vklad a zisk portfólia. */
    public function portfolio(User $user): array
    {
        $value = 0.0;
        $cost = 0.0;
        foreach ($user->investments()->get(['units', 'buy_price', 'current_price']) as $inv) {
            $value += (float) $inv->units * (float) $inv->current_price;
            $cost += (float) $inv->units * (float) $inv->buy_price;
        }
        $gain = $value - $cost;

        return [
            'value' => $value,
            'cost' => $cost,
            'gain' => $gain,
            'pct' => $cost > 0 ? $gain / $cost * 100 : 0,
        ];
    }

    /** Súčet zostatkov na účtoch. */
    public function cash(User $user): float
    {
        return (float) $user->accounts()->sum('balance');
    }

    /** Najbližšie platby v horizonte N dní: predplatné + splátky úverov, zoradené podľa dátumu. */
    public function upcomingPayments(User $user, int $days = 30): array
    {
        $limit = CarbonImmutable::today()->addDays($days)->toDateString();

        $subs = $user->subscriptions()
            ->whereNotNull('next_payment')
            ->where('next_payment', '<=', $limit)
            ->get(['name', 'amount', 'next_payment', 'color'])
            ->map(fn ($s) => [
                'name' => $s->name,
                'amount' => (float) $s->amount,
                'date' => $s->next_payment->toDateString(),
                'color' => $s->color,
                'kind' => 'subscription',
            ]);

        $loans = $user->loans()
            ->where('kind', 'owe')
            ->where('payment', '>', 0)
            ->whereNotNull('next_payment')
            ->where('next_payment', '<=', $limit)
            ->get(['name', 'payment', 'next_payment', 'color'])
            ->map(fn ($l) => [
                'name' => $l->name,
                'amount' => (float) $l->payment,
                'date' => $l->next_payment->toDateString(),
                'color' => $l->color,
                'kind' => 'loan',
            ]);

        $items = $subs->concat($loans)->sortBy('date')->values();

        return [
            'items' => $items->take(8)->all(),
            'count' => $items->count(),
            'total' => round((float) $items->sum('amount'), 2),
            'days' => $days,
        ];
    }

    /** Finančná rezerva: koľko mesiacov priemerných výdavkov pokryje hotovosť. */
    public function reserve(User $user): array
    {
        $avg = $this->avgMonthlyExpense($user);
        $cash = $this->cash($user);

        return [
            'avgExpense' => round($avg, 2),
            'months' => $avg > 0 ? round($cash / $avg, 1) : null,
        ];
    }

    /** Priemerné mesačné výdavky za posledných 6 ukončených mesiacov s dátami. */
    protected function avgMonthlyExpense(User $user): float
    {
        $today = CarbonImmutable::today();
        $sum = 0.0;
        $counted = 0;

        for ($i = 1; $i <= 6; $i++) {
            $m = $today->subMonthsNoOverflow($i);
            $exp = (float) $user->transactions()->analyzed()
                ->where('type', 'expense')
                ->whereBetween('date', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])
                ->sum('amount');
            if ($exp > 0) {
                $sum += $exp;
                $counted++;
            }
        }

        return $counted > 0 ? $sum / $counted : 0.0;
    }

    /** Čerpanie rozpočtov v ich aktuálnom období + projekcia tempa. */
    public function budgetProgress(User $user): Collection
    {
        $today = CarbonImmutable::today();

        // Rozpočet na skupinu (nadradenú kategóriu) zahŕňa aj všetky jej podkategórie
        $childrenByParent = $user->categories()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        return $user->budgets()->get()->map(function ($b) use ($user, $today, $childrenByParent) {
            [$from, $total] = match ($b->period) {
                'week' => [$today->startOfWeek(), 7],
                'year' => [$today->startOfYear(), (int) $today->daysInYear],
                default => [$today->startOfMonth(), (int) $today->daysInMonth],
            };

            $children = $childrenByParent->get($b->category_id);
            $catIds = collect([$b->category_id])->merge($children?->pluck('id') ?? [])->all();

            $spent = (float) $user->transactions()->analyzed()
                ->where('type', 'expense')
                ->whereIn('category_id', $catIds)
                ->where('date', '>=', $from->toDateString())
                ->sum('amount');

            // Projekcia tempa: koľko sa minie do konca obdobia pri aktuálnom tempe
            $elapsed = max(1, $from->diffInDays($today) + 1);
            $projected = $spent / $elapsed * $total;

            return [
                'id' => $b->id,
                'category_id' => $b->category_id,
                'limit_amount' => (float) $b->limit_amount,
                'period' => $b->period,
                'spent' => $spent,
                'projected' => round($projected, 2),
                'elapsed' => $elapsed,
                'total' => $total,
                'is_group' => count($catIds) > 1,
            ];
        })->values();
    }

    /** Príjmy/výdavky za posledných N dní (vrátane dneška). */
    public function flowSince(User $user, CarbonImmutable $from): array
    {
        $rows = $user->transactions()->analyzed()
            ->where('date', '>=', $from->toDateString())
            ->get(['type', 'amount']);

        $income = (float) $rows->where('type', 'income')->sum('amount');
        $expense = (float) $rows->where('type', 'expense')->sum('amount');

        return [
            'income' => $income,
            'expense' => $expense,
            'saved' => $income - $expense,
        ];
    }

    /** Výdavky zoskupené podľa kategórie (category_id) od dátumu. */
    public function expensesByCategory(User $user, CarbonImmutable $from): Collection
    {
        return $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->where('date', '>=', $from->toDateString())
            ->get(['category_id', 'amount'])
            ->groupBy('category_id')
            ->map(fn ($rows, $catId) => [
                'category_id' => (int) $catId,
                'amount' => (float) $rows->sum('amount'),
            ])
            ->sortByDesc('amount')
            ->values();
    }

    /** Príjmy/výdavky/úspory po mesiacoch za posledných N mesiacov. */
    public function monthlyHistory(User $user, int $months = 6): Collection
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths($months - 1);

        $rows = $user->transactions()->analyzed()
            ->where('date', '>=', $start->toDateString())
            ->get(['type', 'amount', 'date']);

        $out = collect();
        for ($i = 0; $i < $months; $i++) {
            $m = $start->addMonths($i);
            $monthRows = $rows->filter(fn ($t) => $t->date->format('Y-m') === $m->format('Y-m'));
            $income = (float) $monthRows->where('type', 'income')->sum('amount');
            $expense = (float) $monthRows->where('type', 'expense')->sum('amount');
            $out->push([
                'label' => $this->monthLabel($m->month),
                'income' => $income,
                'expense' => $expense,
                'saved' => max(0, $income - $expense),
            ]);
        }

        return $out;
    }

    /** Príjmy/výdavky/čistý tok + investované (nákupy lots) po rokoch (medziročne). */
    public function yearlyHistory(User $user): Collection
    {
        $rows = $user->transactions()->analyzed()->get(['type', 'amount', 'date']);

        $lots = InvestmentTransaction::query()
            ->whereIn('investment_id', $user->investments()->select('id'))
            ->get(['type', 'units', 'price', 'date']);

        $txYears = $rows->groupBy(fn ($t) => $t->date->year);
        $lotYears = $lots->groupBy(fn ($l) => $l->date->year);

        return $txYears->keys()
            ->merge($lotYears->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function ($year) use ($txYears, $lotYears) {
                $yearRows = $txYears->get($year, collect());
                $income = (float) $yearRows->where('type', 'income')->sum('amount');
                $expense = (float) $yearRows->where('type', 'expense')->sum('amount');

                $yearLots = $lotYears->get($year, collect());
                $lotValue = fn ($l) => (float) $l->units * (float) $l->price;
                $invested = (float) $yearLots->where('type', 'buy')->sum($lotValue);
                $sold = (float) $yearLots->where('type', 'sell')->sum($lotValue);

                return [
                    'year' => (int) $year,
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                    'rate' => $income > 0 ? ($income - $expense) / $income * 100 : 0,
                    'invested' => round($invested, 2),
                    'sold' => round($sold, 2),
                    'investedPct' => $income > 0 ? round($invested / $income * 100, 1) : null,
                ];
            });
    }

    protected function monthLabel(int $month): string
    {
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'][$month];
    }
}
