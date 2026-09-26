<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Návrh kategórie podľa poznámky — „Lidl" si zaraďoval do Potravín, tak to
 * ponúkne aj nabudúce. Nič neuhádne, len zopakuje tvoje vlastné rozhodnutie.
 */
class CategorySuggester
{
    /** Koľko posledných zhodných transakcií sa pozerá pri hlasovaní o kategórii. */
    protected const LOOKBACK = 200;

    /** Kratšie poznámky sú príliš nejednoznačné na to, aby sa z nich dalo hádať. */
    protected const MIN_WORD = 3;

    /** Nanajvýš toľko slov z poznámky sa vyskúša ako záchytný bod pre SQL. */
    protected const MAX_PROBES = 3;

    public function suggest(User $user, string $note, string $type): ?int
    {
        $key = $this->key($note);
        if ($key === '') {
            return null;
        }

        // Poznámka „Lidl Bratislava" nesmie padnúť len preto, že najdlhšie slovo
        // je mesto — skúšame slová od najdlhšieho, kým niečo nesadne
        foreach ($this->probeWords($note) as $probe) {
            $rows = $this->history($user, $type, fn ($q) => $q->where('note', 'like', '%'.$probe.'%'));

            if ($winner = $this->vote($rows, $key)) {
                return $winner;
            }
        }

        // LIKE v SQLite nepozná diakritiku (MySQL áno), takže cez probe prejde
        // „Lidl", ale nie „Kaviareň". Posledná šanca: porovnať kľúče v PHP nad
        // ohraničeným oknom nedávnych transakcií.
        return $this->vote($this->history($user, $type), $key);
    }

    /**
     * Nedávne zaradené transakcie daného typu.
     *
     * @param  callable(Builder): mixed|null  $filter
     * @return Collection<int, Transaction>
     */
    protected function history(User $user, string $type, ?callable $filter = null)
    {
        $query = $user->transactions()
            ->where('type', $type)
            ->whereNotNull('category_id')
            ->whereNotNull('note');

        if ($filter) {
            $filter($query);
        }

        return $query->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::LOOKBACK)
            ->get(['category_id', 'note']);
    }

    /**
     * Najčastejšie použitá kategória spomedzi zhodných poznámok. Pri rovnakom
     * počte rozhodne tá z novšej transakcie — riadky prichádzajú od najnovšej
     * a groupBy poradie zachováva.
     *
     * @param  Collection<int, Transaction>  $rows
     */
    protected function vote($rows, string $key): ?int
    {
        // Presná zhoda poznámky má prednosť; inak stačí, že sa jedna v druhej nachádza
        $exact = $rows->filter(fn ($t) => $this->key($t->note) === $key);
        $matches = $exact->isNotEmpty()
            ? $exact
            : $rows->filter(function ($t) use ($key) {
                $other = $this->key($t->note);

                return $other !== '' && (str_contains($other, $key) || str_contains($key, $other));
            });

        if ($matches->isEmpty()) {
            return null;
        }

        return (int) $matches->groupBy('category_id')
            ->sortByDesc(fn ($g) => $g->count())
            ->keys()
            ->first();
    }

    /**
     * Slová poznámky od najdlhšieho — hrubé predfiltrovanie v SQL
     * (LIKE ignoruje veľkosť písmen aj diakritiku).
     *
     * @return list<string>
     */
    protected function probeWords(string $note): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $note, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->map(fn ($w) => mb_strtolower($w))
            ->filter(fn ($w) => mb_strlen($w) >= self::MIN_WORD)
            ->unique()
            ->sortByDesc(fn ($w) => mb_strlen($w))
            ->take(self::MAX_PROBES)
            ->values()
            ->all();
    }

    /** Porovnávací tvar poznámky: malé písmená, bez diakritiky, čísel a interpunkcie. */
    protected function key(?string $note): string
    {
        $s = mb_strtolower(trim((string) $note));
        $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s) ?: $s;
        $s = preg_replace('/[^a-z ]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
