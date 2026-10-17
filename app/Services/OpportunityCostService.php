<?php

namespace App\Services;

/**
 * Čím by výdavok bol, keby tie peniaze išli do portfólia. Všetko v dnešných
 * eurách — výnos je reálny (po inflácii aj poplatkoch), takže výsledok je
 * priamo porovnateľný s dnešnými cenami. Používa ho len stránka „Oplatí sa?".
 */
class OpportunityCostService
{
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
}
