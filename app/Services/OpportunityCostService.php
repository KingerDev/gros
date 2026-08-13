<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Čo ťa pravidelný výdavok stojí naozaj. Nie 15 € mesačne, ale to, čím by
 * tých 15 € bolo do dôchodku, keby si ich investoval. Všetko v dnešných
 * eurách — reálny výnos je už po inflácii, poplatkoch aj zrážke, takže
 * výsledok je priamo porovnateľný s dnešnými cenami.
 */
class OpportunityCostService
{
    public function __construct(
        protected RetirementService $retirement,
        protected ExpenseClassifier $classifier,
    ) {}

    /**
     * Predpoklady prevzaté z dôchodkového plánu, aby v celej appke sedeli.
     *
     * @return array{years: int, retire_year: int, real_return: float}
     */
    public function context(User $user): array
    {
        $retireYear = (int) ($user->retire_year ?? 2065);
        $years = max(0, $retireYear - CarbonImmutable::today()->year);

        return [
            'years' => $years,
            'retire_year' => $retireYear,
            'real_return' => $this->retirement->realReturnAssumption($user),
        ];
    }

    /** Budúca hodnota pravidelnej mesačnej sumy, v dnešných eurách. */
    public function fromMonthly(float $amount, int $years, float $realRatePct): float
    {
        $n = $years * 12;
        if ($n <= 0 || $amount <= 0) {
            return 0.0;
        }

        $monthly = (1 + $realRatePct / 100) ** (1 / 12) - 1;
        if ($monthly <= 1e-9) {
            return round($amount * $n, 2);
        }

        return round($amount * (((1 + $monthly) ** $n - 1) / $monthly), 2);
    }

    /** Budúca hodnota jednorazovej sumy, v dnešných eurách. */
    public function fromLumpSum(float $amount, int $years, float $realRatePct): float
    {
        return round($amount * (1 + $realRatePct / 100) ** $years, 2);
    }

    /**
     * Doživotná cena každého predplatného.
     *
     * @return array<int, array<string, mixed>>
     */
    public function subscriptions(User $user): array
    {
        $ctx = $this->context($user);

        return $user->subscriptions()->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'monthly' => round($s->monthly_amount, 2),
                'lifetime' => $this->fromMonthly($s->monthly_amount, $ctx['years'], $ctx['real_return']),
            ])
            ->keyBy('id')
            ->all();
    }

    /**
     * Priemerný mesačný výdavok podľa kategórie za posledných N ukončených
     * mesiacov + čím by tá suma bola do dôchodku. Zrolované na skupiny,
     * rovnako ako to robia rozpočty.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(User $user, int $months = 12, int $limit = 8): array
    {
        $ctx = $this->context($user);
        $today = CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths($months);
        $to = $today->startOfMonth()->subDay(); // len ukončené mesiace

        if ($ctx['years'] <= 0) {
            return [];
        }

        // investovanie nie je náklad — je to práve to, s čím sa tu porovnáva
        $rows = $this->classifier->excludeSavings($user->transactions()->analyzed(), $user)
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('category_id, '.Transaction::netSum('amount'))
            ->groupBy('category_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $parentOf = $user->categories()->pluck('parent_id', 'id');

        $byGroup = [];
        foreach ($rows as $r) {
            $catId = (int) $r->category_id;
            $groupId = (int) ($parentOf[$catId] ?? $catId);
            $byGroup[$groupId] = ($byGroup[$groupId] ?? 0) + (float) $r->amount;
        }
        arsort($byGroup);

        $out = [];
        foreach (array_slice($byGroup, 0, $limit, true) as $categoryId => $total) {
            $monthly = $total / $months;
            if ($monthly < 1) {
                continue;
            }
            $out[] = [
                'category_id' => $categoryId,
                'monthly' => round($monthly, 2),
                'lifetime' => $this->fromMonthly($monthly, $ctx['years'], $ctx['real_return']),
            ];
        }

        return $out;
    }
}
