<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Umorovanie úveru a podklad k najčastejšej otázke osobných financií:
 * mám voľné peniaze poslať na splátku, alebo do portfólia?
 *
 * Obe strany sa merajú rovnako — k tomu istému dátumu a nominálne, lebo aj
 * úroková sadzba úveru je nominálna. Verdikt sa zámerne nevynáša: ušetrený
 * úrok je istý, výnos portfólia len očakávaný. Rozhodnúť musí človek.
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
        $expected = $this->retirement->expectedNominalReturn();

        $loans = $user->loans()->where('kind', 'owe')->where('balance', '>', 0)->get();
        $plans = [];
        foreach ($loans as $loan) {
            $plan = $this->forLoan($loan, $extra, $expected);
            if ($plan !== null) {
                $plans[$loan->id] = $plan;
            }
        }

        return [
            'extra' => $extra,
            // nominálny očakávaný výnos — porovnateľný so sadzbou úveru
            'expected_return' => $expected,
            'real_return' => $this->retirement->realReturnAssumption($user),
            'inflation' => RetirementService::LONG_RUN_INFLATION,
            'loans' => $plans,
            // ktorý dlh sa oplatí splácať prednostne (najvyššia sadzba)
            'priority_id' => $this->priorityLoanId($loans),
        ];
    }

    /**
     * @param  float  $expectedReturnPct  očakávaný NOMINÁLNY výnos portfólia
     * @return array<string, mixed>|null null, ak sa úver pri danej splátke
     *                                   nikdy nesplatí (splátka nepokryje úrok)
     */
    public function forLoan(Loan $loan, float $extra, float $expectedReturnPct): ?array
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
            'compare' => $faster === null ? null : $this->compare($payment, $extra, $base['months'], $faster['months'], $rate, $expectedReturnPct),
        ];
    }

    /**
     * Porovnanie dvoch stratégií na rovnakom horizonte — po mesiaci, v ktorom
     * by úver skončil aj bez mimoriadnych splátok. Obe končia bez dlhu, takže
     * rozhoduje to, čo zostane v portfóliu:
     *
     *  A) Splácať skôr — úver zmizne v mesiaci $fastMonths a odvtedy ide do
     *     portfólia celá splátka aj to extra.
     *  B) Investovať hneď — extra ide do portfólia od začiatku, úver beží
     *     podľa pôvodného plánu.
     *
     * Výsledok B platí len vtedy, ak trh naozaj prinesie očakávaný výnos;
     * výsledok A je istý. Preto sa vracia aj sadzba úveru ako „istý výnos".
     *
     * @return array<string, mixed>
     */
    protected function compare(float $payment, float $extra, int $baseMonths, int $fastMonths, float $loanRatePct, float $expectedReturnPct): array
    {
        $repayFirst = $this->futureValueOfMonthly($payment + $extra, max(0, $baseMonths - $fastMonths), $expectedReturnPct);
        $investFirst = $this->futureValueOfMonthly($extra, $baseMonths, $expectedReturnPct);

        return [
            'horizon_months' => $baseMonths,
            // splatenie úveru je istý výnos vo výške jeho sadzby
            'guaranteed_return' => round($loanRatePct, 2),
            'expected_return' => round($expectedReturnPct, 2),
            'repay_first' => round($repayFirst, 2),
            'invest_first' => round($investFirst, 2),
            // kladné = investovanie vychádza lepšie, ak sa očakávanie naplní
            'advantage' => round($investFirst - $repayFirst, 2),
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

    /** Budúca hodnota mesačného vkladu (nominálne, ako splátky úveru). */
    protected function futureValueOfMonthly(float $amount, int $months, float $ratePct): float
    {
        if ($months <= 0 || $amount <= 0) {
            return 0.0;
        }

        $r = (1 + $ratePct / 100) ** (1 / 12) - 1;
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
