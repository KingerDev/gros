<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Mesačná séria čistého imania = hotovosť + portfólio − dlhy.
 * Hotovosť sa rekonštruuje spätne z aktuálnych zostatkov a tokov transakcií,
 * portfólio z PortfolioHistoryService, dlhy sa odhadujú spätne zo splátok.
 */
class NetWorthService
{
    public function __construct(protected PortfolioHistoryService $portfolioHistory) {}

    /** @return array<int, array<string, mixed>> */
    public function monthlySeries(User $user, int $maxMonths = 24): array
    {
        $today = CarbonImmutable::today();
        $end = $today->startOfMonth();

        $firstTx = $user->transactions()->min('date');
        $start = $firstTx ? CarbonImmutable::parse($firstTx)->startOfMonth() : $end;
        if ($start->diffInMonths($end) + 1 > $maxMonths) {
            $start = $end->subMonths($maxMonths - 1);
        }

        $months = [];
        for ($m = $start; $m <= $end; $m = $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        $cash = $this->cashSeries($user, $months, $start);
        $invest = $this->investSeries($user, $months);
        $debt = $this->debtSeries($user, $months, $end);

        $series = [];
        foreach ($months as $ym) {
            $series[] = [
                'ym' => $ym,
                'label' => $this->label($ym),
                'cash' => round($cash[$ym], 2),
                'invest' => round($invest[$ym], 2),
                'debt' => round($debt[$ym], 2),
                'value' => round($cash[$ym] + $invest[$ym] - $debt[$ym], 2),
            ];
        }

        return $series;
    }

    /**
     * Hotovosť na konci mesiaca = aktuálny súčet zostatkov mínus čistý tok
     * (príjmy − výdavky) všetkých neskorších mesiacov. Prevody sú interné.
     *
     * @param  array<int, string>  $months
     * @return array<string, float>
     */
    protected function cashSeries(User $user, array $months, CarbonImmutable $start): array
    {
        $currentCash = (float) $user->accounts()->sum('balance');

        $rows = $user->transactions()
            ->where('type', '!=', 'transfer')
            ->where('date', '>=', $start->toDateString())
            ->get(['type', 'amount', 'date']);

        $netFlow = [];
        foreach ($rows as $r) {
            $ym = $r->date->format('Y-m');
            $netFlow[$ym] = ($netFlow[$ym] ?? 0.0) + ($r->type === 'income' ? 1 : -1) * (float) $r->amount;
        }

        $cash = [];
        $running = $currentCash;
        for ($i = count($months) - 1; $i >= 0; $i--) {
            $ym = $months[$i];
            $cash[$ym] = $running;
            $running -= $netFlow[$ym] ?? 0.0;
        }

        return $cash;
    }

    /**
     * Hodnota portfólia po mesiacoch. Pred prvým nákupom 0; ak história nie je
     * k dispozícii (žiadne loty / nedostupné ceny), aktuálna hodnota naplocho.
     *
     * @param  array<int, string>  $months
     * @return array<string, float>
     */
    protected function investSeries(User $user, array $months): array
    {
        $map = collect($this->portfolioHistory->monthlySeries($user)['series'])->pluck('value', 'ym')->all();

        $currentValue = 0.0;
        foreach ($user->investments()->get(['units', 'current_price']) as $inv) {
            $currentValue += (float) $inv->units * (float) $inv->current_price;
        }

        $out = [];
        $last = 0.0;
        foreach ($months as $ym) {
            if ($map) {
                $last = $map[$ym] ?? $last;
                $out[$ym] = $last;
            } else {
                $out[$ym] = $currentValue;
            }
        }

        // najnovší mesiac vždy živá hodnota
        if ($months) {
            $out[end($months)] = $currentValue;
        }

        return $out;
    }

    /**
     * Dlhy (kind=owe) spätne: zostatok pred N mesiacmi ≈ dnešný zostatok
     * + N × splátka, ohraničené istinou. Bez splátky konštantný zostatok.
     *
     * @param  array<int, string>  $months
     * @return array<string, float>
     */
    protected function debtSeries(User $user, array $months, CarbonImmutable $end): array
    {
        $loans = $user->loans()->where('kind', 'owe')->get(['balance', 'principal', 'payment']);

        $out = [];
        foreach ($months as $ym) {
            $monthsAgo = CarbonImmutable::parse($ym.'-01')->diffInMonths($end);
            $sum = 0.0;
            foreach ($loans as $loan) {
                $est = (float) $loan->balance + $monthsAgo * (float) $loan->payment;
                if ((float) $loan->principal > 0) {
                    $est = min($est, (float) $loan->principal);
                }
                $sum += $est;
            }
            $out[$ym] = $sum;
        }

        return $out;
    }

    protected function label(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        $short = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

        return $short[(int) $m].' '.substr($y, 2);
    }
}
