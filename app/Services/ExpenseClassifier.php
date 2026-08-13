<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rozlišuje tri druhy „výdavkov", ktoré vyzerajú rovnako, ale znamenajú
 * niečo úplne iné:
 *
 *  - **spotreba** — peniaze, ktoré sú preč
 *  - **sporenie** — peniaze poslané do portfólia; nie sú minuté, len presunuté
 *  - **jednorazovky** — rovnátka, havária, nový notebook; v ročnom priemere
 *    vyzerajú ako pravidelný náklad, hoci sa nezopakujú
 *
 * Bez tohto rozlíšenia vychádza miera úspor záporná u človeka, ktorý si
 * poctivo odkladá — presne preto to tu je na jednom mieste pre všetky
 * stránky, ktoré s výdavkami pracujú.
 */
class ExpenseClassifier
{
    /** Skupiny kategórií, ktoré predstavujú sporenie zaúčtované ako výdavok. */
    public const SAVINGS_GROUPS = ['investície'];

    /**
     * Id kategórií, ktoré sú v skutočnosti sporenie.
     *
     * @return array<int, int>
     */
    public function savingsCategoryIds(User $user): array
    {
        $categories = $user->categories()->where('type', 'expense')->get(['id', 'name', 'parent_id', 'is_savings']);
        $flagged = $categories->where('is_savings', true)->pluck('id');

        // podkategórie dedia príznak zo skupiny
        return $categories
            ->filter(fn ($c) => $c->is_savings || ($c->parent_id && $flagged->contains($c->parent_id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Odfiltruje z dotazu presuny do portfólia. Používa sa všade, kde ide
     * o spotrebu — teda pri výdavkoch, miere úspor aj rezerve.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function excludeSavings($query, User $user)
    {
        $ids = $this->savingsCategoryIds($user);

        return $ids ? $query->whereNotIn('category_id', $ids) : $query;
    }

    /**
     * Nájde výdavky, ktoré sa zjavne nebudú opakovať. Kritérium nie je veľkosť
     * sumy, ale to, že kategória v danom mesiaci vyskočila vysoko nad svoj
     * bežný mesiac — vďaka tomu sa chytí aj náklad rozdelený na splátky.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, int>
     */
    public function oneOffIds(Collection $transactions, int $months): array
    {
        $flagged = [];

        foreach ($transactions->groupBy('category_id') as $categoryId => $rows) {
            if ($categoryId === '' || $categoryId === null) {
                continue;
            }

            $byMonth = [];
            foreach ($rows as $t) {
                $ym = $t->date->format('Y-m');
                $byMonth[$ym] = ($byMonth[$ym] ?? 0) + (float) $t->net_amount;
            }

            // mesiace bez pohybu patria do rozdelenia tiež
            $totals = array_pad(array_values($byMonth), $months, 0.0);

            // hranica: trojnásobok bežného mesiaca, minimálne 200 €, aby sa
            // malé kategórie nezačali označovať pri každom väčšom nákupe
            $threshold = max(3 * $this->median($totals), 200.0);

            foreach ($byMonth as $ym => $monthTotal) {
                if ($monthTotal <= $threshold) {
                    continue;
                }
                foreach ($rows as $t) {
                    if ($t->date->format('Y-m') === $ym && (float) $t->net_amount >= 200.0) {
                        $flagged[] = $t->id;
                    }
                }
            }
        }

        return $flagged;
    }

    /** @param array<int, float> $values */
    public function median(array $values): float
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
