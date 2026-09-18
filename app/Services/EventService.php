<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Súčty a rozklady udalostí (dovolenka, svadba…).
 *
 * Do súčtu udalosti idú výdavky tak, ako do analýz: po odrátaní vrátení
 * a bez tých, ktoré sú vylúčené z analýzy (napr. preplatené firmou) —
 * tie sa na stránke udalosti len ukážu.
 */
class EventService
{
    /**
     * Všetky udalosti so súčtami — na zoznam.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function overview(User $user): Collection
    {
        $totals = $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->whereNotNull('event_id')
            ->selectRaw('event_id, '.Transaction::netSum('amount').', count(*) as cnt')
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        return $user->events()->orderByDesc('starts_on')->orderByDesc('id')->get()
            ->map(function (Event $e) use ($totals) {
                $total = round((float) ($totals[$e->id]->amount ?? 0), 2);

                return $this->card($e) + [
                    'total' => $total,
                    'count' => (int) ($totals[$e->id]->cnt ?? 0),
                    'per_day' => round($total / $e->days(), 2),
                ];
            });
    }

    /** Detail udalosti: súčty, rozklad podľa kategórie, dní a účtov + transakcie. */
    public function detail(Event $event): array
    {
        $all = $event->transactions()
            ->with(['account:id,name,color', 'refunds:id,refund_for_id,amount,date,note,account_id'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // do súčtov len to, čo ide aj do analýz
        $counted = $all->filter(fn (Transaction $t) => $t->type === 'expense' && ! $t->excluded_from_analytics && ! $t->isRefund());
        $total = round((float) $counted->sum('net_amount'), 2);

        return [
            'event' => $this->card($event),
            'total' => $total,
            'count' => $counted->count(),
            'per_day' => round($total / $event->days(), 2),
            'refunded' => round((float) $counted->sum('refunded_amount'), 2),
            'excluded' => [
                'count' => $all->where('excluded_from_analytics', true)->count(),
                'amount' => round((float) $all->where('excluded_from_analytics', true)->sum('net_amount'), 2),
            ],
            'byCategory' => $this->byCategory($event->user, $counted),
            'byDay' => $this->byDay($event, $counted),
            'byAccount' => $counted->groupBy('account_id')
                ->map(fn ($rows) => [
                    'account_id' => (int) $rows->first()->account_id,
                    'name' => $rows->first()->account?->name ?? '—',
                    'color' => $rows->first()->account?->color ?? '#94a3b8',
                    'amount' => round((float) $rows->sum('net_amount'), 2),
                    'count' => $rows->count(),
                ])
                ->sortByDesc('amount')
                ->values(),
            'transactions' => $all->sortByDesc(fn ($t) => $t->date->format('Y-m-d').sprintf('%010d', $t->id))->values(),
        ];
    }

    /**
     * Výdavky, ktoré sa dajú k udalosti priradiť — na výber v okne
     * „Pridať transakcie". Vrátenia sa nepriraďujú, idú so svojím nákupom.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(Event $event, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $event->user->transactions()
            ->with(['account:id,name', 'event:id,name,color,icon'])
            ->where('type', 'expense')
            ->whereNull('refund_for_id')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'date' => $t->date->toDateString(),
                'note' => $t->note,
                'category_id' => $t->category_id,
                'account' => $t->account?->name,
                'amount' => $t->net_amount,
                'excluded' => $t->excluded_from_analytics,
                'event_id' => $t->event_id,
                'event' => $t->event && (int) $t->event_id !== $event->id
                    ? ['name' => $t->event->name, 'color' => $t->event->color, 'icon' => $t->event->icon]
                    : null,
                'in_range' => ! $t->date->lt($event->starts_on) && ! $t->date->gt($event->ends_on),
            ]);
    }

    /**
     * Koľko išlo na jednotlivé udalosti v rámci obdobia (napr. v Analýzach).
     * Ráta sa len časť udalosti, ktorá do obdobia padla — letenka kúpená
     * v júni patrí júnu, aj keď sa letelo v septembri.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function inPeriod(User $user, Period $period): Collection
    {
        $rows = $period->apply(
            $user->transactions()->analyzed()->where('type', 'expense')->whereNotNull('event_id')
        )
            ->selectRaw('event_id, '.Transaction::netSum('amount').', count(*) as cnt')
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        if ($rows->isEmpty()) {
            return collect();
        }

        $overall = $this->overview($user)->keyBy('id');

        return $user->events()->whereIn('id', $rows->keys())->get()
            ->map(fn (Event $e) => $this->card($e) + [
                'amount' => round((float) $rows[$e->id]->amount, 2),
                'count' => (int) $rows[$e->id]->cnt,
                'total' => $overall[$e->id]['total'] ?? 0.0,
            ])
            ->sortByDesc('amount')
            ->values();
    }

    /** @return array<string, mixed> */
    public function card(Event $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'starts_on' => $e->starts_on->toDateString(),
            'ends_on' => $e->ends_on->toDateString(),
            'days' => $e->days(),
            'budget' => $e->budget === null ? null : (float) $e->budget,
            'color' => $e->color,
            'icon' => $e->icon,
            'note' => $e->note,
        ];
    }

    /**
     * Po kategóriách, zrolované do skupín (ako v Analýzach), s podkategóriami.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function byCategory(User $user, Collection $rows): array
    {
        $parentOf = $user->categories()->pluck('parent_id', 'id');

        $groups = [];
        foreach ($rows->groupBy('category_id') as $catId => $txns) {
            $catId = (int) $catId;
            $groupId = (int) ($parentOf[$catId] ?? $catId);
            $amount = (float) $txns->sum('net_amount');

            $groups[$groupId] ??= ['category_id' => $groupId, 'amount' => 0.0, 'count' => 0, 'children' => []];
            $groups[$groupId]['amount'] += $amount;
            $groups[$groupId]['count'] += $txns->count();
            $groups[$groupId]['children'][] = ['category_id' => $catId, 'amount' => round($amount, 2), 'count' => $txns->count()];
        }

        return collect($groups)
            ->map(function (array $g) {
                $onlySelf = count($g['children']) === 1 && $g['children'][0]['category_id'] === $g['category_id'];
                $g['children'] = $onlySelf ? [] : collect($g['children'])->sortByDesc('amount')->values()->all();
                $g['amount'] = round($g['amount'], 2);

                return $g;
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Po dňoch udalosti. Čo sa zaplatilo vopred (letenka, ubytovanie) alebo
     * dodatočne, ide do samostatných stĺpcov „Vopred" a „Potom" — inak by
     * graf dní nemal jasnú os.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function byDay(Event $event, Collection $rows): array
    {
        // dlhé udalosti (sťahovanie na dva mesiace) by mali stovky stĺpcov — tam dni nemajú zmysel
        if ($event->days() > 62) {
            return [];
        }

        $start = CarbonImmutable::parse($event->starts_on);
        $end = CarbonImmutable::parse($event->ends_on);

        $sums = [];
        $before = 0.0;
        $after = 0.0;
        foreach ($rows as $t) {
            $d = $t->date->toDateString();
            if ($d < $start->toDateString()) {
                $before += (float) $t->net_amount;
            } elseif ($d > $end->toDateString()) {
                $after += (float) $t->net_amount;
            } else {
                $sums[$d] = ($sums[$d] ?? 0) + (float) $t->net_amount;
            }
        }

        $out = [];
        if ($before > 0) {
            $out[] = ['date' => null, 'label' => 'Vopred', 'amount' => round($before, 2)];
        }
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'label' => $d->format('j.n.'), 'amount' => round($sums[$d->toDateString()] ?? 0, 2)];
        }
        if ($after > 0) {
            $out[] = ['date' => null, 'label' => 'Potom', 'amount' => round($after, 2)];
        }

        return $out;
    }
}
