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
 *  - **jednorazovky** — rovnátka, havária, nový notebook, dovolenka; v ročnom
 *    priemere vyzerajú ako pravidelný náklad, hoci sa nezopakujú
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
     * Id kategórií, ktoré sú len presun peňazí — na výdavkovej strane vklady
     * do portfólia, na príjmovej prijaté či vrátené pôžičky.
     *
     * @return array<int, int>
     */
    public function savingsCategoryIds(User $user): array
    {
        $categories = $user->categories()->get(['id', 'name', 'parent_id', 'is_savings']);
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
     * Odfiltruje z dotazu presuny — vklady do portfólia, prijaté pôžičky aj
     * splátky peňazí, ktoré si niekomu požičal. Používa sa všade, kde ide
     * o spotrebu a príjem — teda pri výdavkoch, miere úspor aj rezerve.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function excludeSavings($query, User $user)
    {
        $ids = $this->savingsCategoryIds($user);
        if ($ids) {
            // whereNotIn by vyradil aj transakcie bez kategórie (NULL NOT IN …)
            $query->where(fn ($q) => $q->whereNull('category_id')->orWhereNotIn('category_id', $ids));
        }

        return $this->excludeLoanIncome($query);
    }

    /**
     * Vyradí len príjmy, ktoré nie sú zárobok (pôžičky), výdavky nechá celé.
     * Pre hrubé prehľady, kde vklady do portfólia ešte patria medzi výdavky.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public function excludeNonIncome($query, User $user)
    {
        $ids = $this->savingsCategoryIds($user);

        return $query->where(fn ($q) => $q->where('type', '!=', 'income')->orWhere(function ($q) use ($ids) {
            if ($ids) {
                $q->where(fn ($q) => $q->whereNull('category_id')->orWhereNotIn('category_id', $ids));
            }
            $this->excludeLoanIncome($q);
        }));
    }

    /** Splátka peňazí, ktoré si niekomu požičal (úver „požičal som"), nie je zárobok. */
    protected function excludeLoanIncome($query)
    {
        return $query->where(fn ($q) => $q->where('type', '!=', 'income')->orWhereNull('source')->orWhere('source', '!=', 'loan'));
    }

    /**
     * Nájde výdavky, ktoré sa zjavne nebudú opakovať.
     *
     * Výdavky priradené k udalosti (dovolenka a pod.) sú jednorazové vždy —
     * používateľ to povedal sám. Pri ostatných rozhoduje heuristika: nie
     * veľkosť sumy, ale to, že kategória v danom mesiaci vyskočila vysoko nad
     * svoj bežný mesiac — vďaka tomu sa chytí aj náklad rozdelený na splátky.
     * Tá sa počíta už bez udalostí, aby dovolenka nenafúkla „bežný mesiac".
     *
     * Kolekcia musí mať načítaný stĺpec event_id.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, int>
     */
    public function oneOffIds(Collection $transactions, int $months): array
    {
        [$fromEvents, $rest] = $transactions->partition(fn ($t) => $t->event_id !== null);

        $flagged = $fromEvents->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($rest->groupBy('category_id') as $categoryId => $rows) {
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
