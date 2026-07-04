<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Mesačný plán míňania ("koľko môžem ešte minúť") + projekcia tempa.
 * Vždy počíta pre AKTUÁLNY kalendárny mesiac.
 */
class SpendingPlanService
{
    private const MONTHS_SK = [
        1 => 'Január', 2 => 'Február', 3 => 'Marec', 4 => 'Apríl', 5 => 'Máj', 6 => 'Jún',
        7 => 'Júl', 8 => 'August', 9 => 'September', 10 => 'Október', 11 => 'November', 12 => 'December',
    ];

    /** @return array<string, mixed> */
    public function current(User $user): array
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $daysInMonth = (int) $today->daysInMonth;
        $dayOfMonth = (int) $today->day;
        $daysLeft = max(1, $daysInMonth - $dayOfMonth + 1); // vrátane dneška

        // Mesačný príjem: ručne nastavený, inak odhad z histórie
        $incomeIsEstimate = $user->monthly_income === null;
        $income = $incomeIsEstimate ? $this->estimatedMonthlyIncome($user) : (float) $user->monthly_income;
        $savings = (float) ($user->savings_goal ?? 0);
        $fixed = $this->monthlyFixed($user);

        // Voľné na míňanie tento mesiac
        $disposable = $income - $fixed - $savings;

        // Už minuté tento mesiac (len výdavky, bez prevodov)
        $spent = (float) $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('amount');

        $safeToSpend = $disposable - $spent;
        $dailyLimit = max(0, $safeToSpend) / $daysLeft;

        // Projekcia podľa tempa (koľko minie do konca mesiaca pri tomto tempe)
        $projectedSpend = $dayOfMonth > 0 ? $spent / $dayOfMonth * $daysInMonth : 0;
        $projectedLeftover = $disposable - $projectedSpend;

        return [
            'configured' => $income > 0,
            'incomeIsEstimate' => $incomeIsEstimate,
            'income' => round($income, 2),
            'fixed' => round($fixed, 2),
            'savings' => round($savings, 2),
            'disposable' => round($disposable, 2),
            'spent' => round($spent, 2),
            'safeToSpend' => round($safeToSpend, 2),
            'dailyLimit' => round($dailyLimit, 2),
            'projectedSpend' => round($projectedSpend, 2),
            'projectedLeftover' => round($projectedLeftover, 2),
            'onTrack' => $projectedLeftover >= 0,
            'daysInMonth' => $daysInMonth,
            'dayOfMonth' => $dayOfMonth,
            'daysLeft' => $daysLeft,
            'monthLabel' => self::MONTHS_SK[(int) $today->month] . ' ' . $today->year,
            'estimateSuggestion' => round($this->estimatedMonthlyIncome($user), 2),
        ];
    }

    /** Fixné mesačné náklady = predplatné (normalizované na mesiac) + splátky úverov (dlžím). */
    protected function monthlyFixed(User $user): float
    {
        $subs = $user->subscriptions()->get(['amount', 'cycle'])
            ->sum(fn ($s) => $s->cycle === 'yearly' ? (float) $s->amount / 12 : (float) $s->amount);

        $loans = (float) $user->loans()->where('kind', 'owe')->sum('payment');

        return (float) $subs + $loans;
    }

    /** Odhad mesačného príjmu = priemer príjmov za posledné 3 ukončené mesiace s dátami. */
    protected function estimatedMonthlyIncome(User $user): float
    {
        $today = CarbonImmutable::today();
        $sum = 0.0;
        $counted = 0;

        for ($i = 1; $i <= 3; $i++) {
            $m = $today->subMonthsNoOverflow($i);
            $inc = (float) $user->transactions()
                ->where('type', 'income')
                ->whereBetween('date', [$m->startOfMonth()->toDateString(), $m->endOfMonth()->toDateString()])
                ->sum('amount');
            if ($inc > 0) {
                $sum += $inc;
                $counted++;
            }
        }

        return $counted > 0 ? $sum / $counted : 0.0;
    }
}
