<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Umorovanie úveru a odpoveď na najčastejšiu otázku osobných financií:
 * mám voľné peniaze poslať na splátku, alebo do portfólia?
 *
 * Porovnanie je poctivé len keď sa obe strany merajú rovnako — preto sa
 * ušetrený úrok aj výnos počítajú k tomu istému dátumu (doplatenie úveru
 * pri mimoriadnej splátke) a v dnešných eurách.
 */
class LoanPlanService
{
    /** Bezpečnostná poistka proti nekonečnému umorovaniu pri príliš nízkej splátke. */
    protected const MAX_MONTHS = 1200;

    public function __construct(protected RetirementService $retirement) {}

    /**
     * Plán pre všetky dlhy používateľa.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user, float $extra = 100.0): array
    {
        $realReturn = $this->retirement->realReturnAssumption($user);

        $loans = $user->loans()->where('kind', 'owe')->where('balance', '>', 0)->get();
        $plans = [];
        foreach ($loans as $loan) {
            $plan = $this->forLoan($loan, $extra, $realReturn);
            if ($plan !== null) {
                $plans[$loan->id] = $plan;
            }
        }

        return [
            'extra' => $extra,
            'real_return' => $realReturn,
            'loans' => $plans,
            // ktorý dlh sa oplatí splácať prednostne (najvyššia sadzba)
            'priority_id' => $this->priorityLoanId($loans),
        ];
    }

    /**
     * @return array<string, mixed>|null null, ak sa úver pri danej splátke
     *                                   nikdy nesplatí (splátka nepokryje úrok)
     */
    public function forLoan(Loan $loan, float $extra, float $realReturnPct): ?array
    {
        $balance = (float) $loan->balance;
        $payment = (float) $loan->payment;
        $rate = (float) $loan->rate;

        if ($balance <= 0 || $payment <= 0) {
            return null;
        }

        $base = $this->amortize($balance, $payment, $rate);
        if ($base === null) {
            return [
                'ok' => false,
                'reason' => 'Splátka nepokryje ani úrok — dlh pri nej rastie.',
                'rate' => $rate,
            ];
        }

        $faster = $this->amortize($balance, $payment + $extra, $rate);
        $start = $loan->next_payment ? CarbonImmutable::parse($loan->next_payment->toDateString()) : CarbonImmutable::today();

        $savedInterest = $faster ? $base['interest'] - $faster['interest'] : 0.0;
        $monthsSaved = $faster ? $base['months'] - $faster['months'] : 0;

        return [
            'ok' => true,
            'rate' => $rate,
            'balance' => round($balance, 2),
            'payment' => round($payment, 2),
            'months' => $base['months'],
            'payoff_date' => $start->addMonths(max(0, $base['months'] - 1))->toDateString(),
            'total_interest' => round($base['interest'], 2),
            'total_paid' => round($balance + $base['interest'], 2),
            'with_extra' => $faster === null ? null : [
                'extra' => round($extra, 2),
                'months' => $faster['months'],
                'months_saved' => $monthsSaved,
                'payoff_date' => $start->addMonths(max(0, $faster['months'] - 1))->toDateString(),
                'interest_saved' => round($savedInterest, 2),
            ],
            'compare' => $faster === null ? null : $this->compare($payment, $extra, $base['months'], $faster['months'], $realReturnPct),
        ];
    }

    /**
     * Poctivé porovnanie dvoch stratégií na rovnakom horizonte — po mesiaci,
     * v ktorom by úver skončil aj bez mimoriadnych splátok. Obe končia bez
     * dlhu, takže rozhoduje čisto to, čo zostane v portfóliu:
     *
     *  A) Splácať skôr — úver zmizne v mesiaci $fastMonths a odvtedy ide do
     *     portfólia celá splátka aj to extra.
     *  B) Investovať hneď — extra ide do portfólia od začiatku, úver beží
     *     podľa pôvodného plánu.
     *
     * @return array<string, mixed>
     */
    protected function compare(float $payment, float $extra, int $baseMonths, int $fastMonths, float $realReturnPct): array
    {
        $repayFirst = $this->futureValueOfMonthly($payment + $extra, max(0, $baseMonths - $fastMonths), $realReturnPct);
        $investFirst = $this->futureValueOfMonthly($extra, $baseMonths, $realReturnPct);

        return [
            'horizon_months' => $baseMonths,
            'real_return' => $realReturnPct,
            'repay_first' => round($repayFirst, 2),
            'invest_first' => round($investFirst, 2),
            // kladné = oplatí sa radšej investovať
            'advantage' => round($investFirst - $repayFirst, 2),
            'verdict' => $investFirst > $repayFirst ? 'invest' : 'repay',
        ];
    }

    /**
     * Umorovací výpočet: koľko mesiacov a koľko úroku pri danej splátke.
     *
     * @return array{months: int, interest: float}|null
     */
    protected function amortize(float $balance, float $payment, float $ratePct): ?array
    {
        $monthlyRate = $ratePct / 100 / 12;

        // bezúročná pôžička — jednoduché delenie
        if ($monthlyRate <= 0) {
            return ['months' => (int) ceil($balance / $payment), 'interest' => 0.0];
        }

        // splátka musí prevýšiť prvý mesačný úrok, inak dlh rastie
        if ($payment <= $balance * $monthlyRate) {
            return null;
        }

        $interest = 0.0;
        $months = 0;
        while ($balance > 0.005 && $months < self::MAX_MONTHS) {
            $accrued = $balance * $monthlyRate;
            $interest += $accrued;
            $balance = $balance + $accrued - $payment;
            $months++;
        }

        // posledná splátka je menšia — vráť späť prečerpanie
        if ($balance < 0) {
            $balance = 0.0;
        }

        return ['months' => $months, 'interest' => round($interest, 2)];
    }

    /** Budúca hodnota mesačného vkladu v dnešných eurách (reálny výnos). */
    protected function futureValueOfMonthly(float $amount, int $months, float $realRatePct): float
    {
        if ($months <= 0 || $amount <= 0) {
            return 0.0;
        }

        $r = (1 + $realRatePct / 100) ** (1 / 12) - 1;
        if ($r <= 1e-9) {
            return $amount * $months;
        }

        return $amount * (((1 + $r) ** $months - 1) / $r);
    }

    /** @param Collection<int, Loan> $loans */
    protected function priorityLoanId($loans): ?int
    {
        $top = $loans->filter(fn ($l) => (float) $l->rate > 0)->sortByDesc(fn ($l) => (float) $l->rate)->first();

        return $top?->id;
    }
}
