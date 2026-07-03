<?php

namespace App\Services;

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

    /** Príjmy/výdavky za posledných N dní (vrátane dneška). */
    public function flowSince(User $user, CarbonImmutable $from): array
    {
        $rows = $user->transactions()
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
        return $user->transactions()
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

        $rows = $user->transactions()
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

    /** Príjmy/výdavky/čistý tok po rokoch (medziročne). */
    public function yearlyHistory(User $user): Collection
    {
        $rows = $user->transactions()->get(['type', 'amount', 'date']);

        return $rows->groupBy(fn ($t) => $t->date->year)
            ->map(function ($yearRows, $year) {
                $income = (float) $yearRows->where('type', 'income')->sum('amount');
                $expense = (float) $yearRows->where('type', 'expense')->sum('amount');

                return [
                    'year' => (int) $year,
                    'income' => $income,
                    'expense' => $expense,
                    'net' => $income - $expense,
                    'rate' => $income > 0 ? ($income - $expense) / $income * 100 : 0,
                ];
            })
            ->sortBy('year')
            ->values();
    }

    protected function monthLabel(int $month): string
    {
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'][$month];
    }
}
