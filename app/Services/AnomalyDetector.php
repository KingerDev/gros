<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Nezvyčajné výdavky — položky, ktoré vyskočili nad to, čo je v danej
 * kategórii bežné.
 *
 * Zámerne bez AI: „koľkonásobok bežnej sumy" je otázka na medián, nie na
 * jazykový model. Štatistika je tu presná, okamžitá, zadarmo a pri rovnakých
 * dátach vždy rovnaká — model by dal drahšiu a menej spoľahlivú odpoveď.
 * AI má zmysel až na vysvetlenie „prečo", a to rieši asistent.
 */
class AnomalyDetector
{
    public function __construct(protected ExpenseClassifier $classifier) {}

    /** Koľko mesiacov histórie tvorí „bežnú" hladinu kategórie. */
    protected const WINDOW = 12;

    /** Nižšie sumy nestoja za upozornenie, aj keď sú násobkom bežnej. */
    protected const MIN_AMOUNT = 40.0;

    /** Koľkonásobok mediánu kategórie je už nezvyčajný. */
    protected const FACTOR = 3.0;

    /**
     * Nezvyčajné výdavky za posledných N dní.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(User $user, int $days = 45, int $limit = 5): array
    {
        $today = CarbonImmutable::today();
        $historyFrom = $today->subMonths(self::WINDOW);
        $recentFrom = $today->subDays($days);

        $history = $this->classifier->excludeSavings($user->transactions()->analyzed(), $user)
            ->with('category:id,name,color')
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->whereDate('date', '>=', $historyFrom->toDateString())
            ->get(['id', 'category_id', 'date', 'note', 'amount', 'refunded_amount']);

        if ($history->isEmpty()) {
            return [];
        }

        // bežná hladina = medián transakcií v kategórii za celé okno
        $normal = $history->groupBy('category_id')
            ->map(fn ($rows) => $this->median($rows->map(fn ($t) => (float) $t->net_amount)->all()));

        $out = [];
        foreach ($history as $t) {
            if ($t->date->lt($recentFrom)) {
                continue;
            }
            $amount = (float) $t->net_amount;
            $median = (float) ($normal[$t->category_id] ?? 0);
            $threshold = max(self::MIN_AMOUNT, $median * self::FACTOR);

            if ($amount <= $threshold || $median <= 0) {
                continue;
            }

            $out[] = [
                'id' => $t->id,
                'date' => $t->date->toDateString(),
                'note' => $t->note,
                'amount' => round($amount, 2),
                'category' => $t->category?->name,
                'color' => $t->category?->color,
                'usual' => round($median, 2),
                'times' => round($amount / $median, 1),
            ];
        }

        usort($out, fn ($a, $b) => $b['times'] <=> $a['times']);

        return array_slice($out, 0, $limit);
    }

    /** @param array<int, float> $values */
    protected function median(array $values): float
    {
        if (! $values) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }
}
