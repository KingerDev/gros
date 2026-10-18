<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Núdzový fond = nevyhnutné mesačné výdavky × počet mesiacov.
 *
 * Bežné odporúčanie je 3–6 mesiacov (Vanguard, CFPB). Kde v tom rozpätí
 * byť, závisí od istoty príjmu a od toho, koľko ľudí od neho závisí — to
 * vie posúdiť len človek sám, preto si počet mesiacov volí. Merané je to,
 * čo merať ide: koľko naozaj míňaš na nevyhnutné veci a koľko máš odložené.
 *
 * Toto je zároveň jediné miesto, kde sa počítajú „bežné mesačné výdavky"
 * — prehľad aj dôchodok berú to isté číslo.
 */
class EmergencyFundService
{
    /** Koľko ukončených mesiacov sa berie do priemerov. */
    protected const WINDOW = 12;

    /** Spodná hranica bežného odporúčania. */
    public const DEFAULT_MONTHS = 3;

    protected const MIN_MONTHS = 1;

    protected const MAX_MONTHS = 12;

    /**
     * Kategórie, ktoré sa štandardne považujú za nevyhnutné — prežitie, nie
     * životná úroveň. Porovnáva sa názov kategórie aj jej skupiny.
     */
    protected const DEFAULT_ESSENTIAL = [
        'bývanie',
        'potraviny',
        'drogéria',
        'mhd',
        'pohonné hmoty',
        'poistenie vozidla',
        'lízing',
        'internet',
        'telefón, mobil',
        'výživné',
        'úvery, úroky',
        'poistenia',
        'dane',
        'zdravotná starostlivosť, lekár',
        // to isté po anglicky (kategórie naseedované v angličtine)
        'housing',
        'groceries',
        'drugstore',
        'public transport',
        'fuel',
        'vehicle insurance',
        'leasing',
        'phone, mobile',
        'child support',
        'loans, interests',
        'insurances',
        'taxes',
        'health care, doctor',
    ];

    public function __construct(
        protected FinanceService $finance,
        protected ExpenseClassifier $classifier,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $profile = $this->profile($user);
        $expenses = $this->essentialExpenses($user, $profile);

        $months = $profile['months'];
        $essential = $expenses['essential'];

        $target = $essential * $months;
        $held = $this->heldReserve($user, $profile, $expenses);

        return [
            'profile' => $profile,
            'expenses' => $expenses,
            'months' => $months,
            'target' => round($target, 2),
            'held' => round($held, 2),
            // z čoho je „odložené" — aby sa dalo ukázať, nie len tvrdiť
            'held_basis' => [
                'source' => $profile['source'],
                'cash' => round($this->finance->cash($user), 2),
                'month_spending' => $expenses['total'],
                'goals' => $profile['source'] === 'account' && $profile['account_id'] ? 0.0 : $this->goalSavings($user),
                'account' => $profile['source'] === 'account' && $profile['account_id']
                    ? $user->accounts()->whereKey($profile['account_id'])->value('name')
                    : null,
            ],
            'covered_months' => $essential > 0 ? round($held / $essential, 1) : null,
            'gap' => round(max(0, $target - $held), 2),
            'progress_pct' => $target > 0 ? round(min(100, $held / $target * 100), 1) : 0,
            'milestones' => $this->milestones($essential, $months, $held),
            'sources' => $this->sources(),
        ];
    }

    /**
     * Nastavenie používateľa s rozumnými východiskami.
     *
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $saved = $user->reserve_profile ?? [];
        // staršie uloženie poznalo len „vlastný počet mesiacov" nad odporúčaním
        $months = $saved['months'] ?? $saved['months_override'] ?? self::DEFAULT_MONTHS;

        return [
            'months' => max(self::MIN_MONTHS, min(self::MAX_MONTHS, (float) $months)),
            // predvolene bez peňazí na tento mesiac — tie sú na nájom, nie na núdzu
            'source' => $this->pick($saved['source'] ?? null, ['all_cash', 'account', 'cash_minus_month'], 'cash_minus_month'),
            'account_id' => isset($saved['account_id']) ? (int) $saved['account_id'] : null,
            'essential_category_ids' => isset($saved['essential_category_ids']) && is_array($saved['essential_category_ids'])
                ? array_map('intval', $saved['essential_category_ids'])
                : null,
            // označené jednorazovky, o ktorých používateľ povedal, že sa opakujú
            'recurring_transaction_ids' => isset($saved['recurring_transaction_ids']) && is_array($saved['recurring_transaction_ids'])
                ? array_map('intval', $saved['recurring_transaction_ids'])
                : [],
        ];
    }

    /**
     * Nevyhnutné vs. celkové mesačné výdavky, priemer za ukončené mesiace.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function essentialExpenses(User $user, array $profile): array
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfMonth()->subMonths(self::WINDOW);
        $to = $today->startOfMonth()->subDay();

        // Splátky úverov sa berú z modelu úveru, nie z transakcií — sú
        // zmluvne dané, platia sa aj v mesiaci, keď sa zabudlo zaúčtovať,
        // a rovno tým odpadá dvojité počítanie.
        $transactions = $user->transactions()->analyzed()
            ->where('type', 'expense')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'loan'))
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'category_id', 'event_id', 'date', 'note', 'amount', 'refunded_amount']);

        $months = max(1, $this->countedMonths($user, $from, $to));

        $essentialIds = $this->essentialCategoryIds($user, $profile);
        $savingsIds = $this->classifier->savingsCategoryIds($user);
        $recurringIds = $profile['recurring_transaction_ids'] ?? [];

        $oneOffIds = $this->classifier->oneOffIds($transactions, $months);

        $total = 0.0;
        $essential = 0.0;
        $savingsFlow = 0.0;
        $oneOffTotal = 0.0;
        $perCategory = [];
        $oneOffs = [];
        $eventTotals = [];

        foreach ($transactions as $t) {
            $amount = (float) $t->net_amount;
            $catId = $t->category_id === null ? null : (int) $t->category_id;

            // sporenie a investovanie nie je spotreba — v kríze proste ustane
            if ($catId !== null && in_array($catId, $savingsIds, true)) {
                $savingsFlow += $amount;

                continue;
            }

            // výdavky z udalostí (dovolenka…) sú jednorazové z definície —
            // nevypisujú sa po jednom, ale ako jeden riadok za udalosť
            if ($t->event_id !== null) {
                $oneOffTotal += $amount;
                $eventTotals[$t->event_id] = ($eventTotals[$t->event_id] ?? 0) + $amount;

                continue;
            }

            $flagged = in_array($t->id, $oneOffIds, true);
            $keptAsRecurring = $flagged && in_array($t->id, $recurringIds, true);

            if ($flagged) {
                $oneOffs[] = [
                    'id' => $t->id,
                    'date' => $t->date->toDateString(),
                    'category_id' => $catId,
                    'note' => $t->note,
                    'amount' => round($amount, 2),
                    'treat_as_recurring' => $keptAsRecurring,
                    'monthly_impact' => round($amount / $months, 2),
                ];
            }

            // jednorazovky nafukujú priemer, preto sa štandardne nerátajú
            if ($flagged && ! $keptAsRecurring) {
                $oneOffTotal += $amount;

                continue;
            }

            $total += $amount;

            if ($catId !== null && in_array($catId, $essentialIds, true)) {
                $essential += $amount;
                $perCategory[$catId] = ($perCategory[$catId] ?? 0) + $amount;
            }
        }

        $loans = $user->loans()->where('kind', 'owe')->where('payment', '>', 0)
            ->get(['id', 'name', 'payment', 'next_payment', 'color'])
            ->map(fn ($l) => [
                'name' => $l->name,
                'payment' => round((float) $l->payment, 2),
                'next_payment' => $l->next_payment?->toDateString(),
                'color' => $l->color,
            ])
            ->all();
        $loanPayments = array_sum(array_column($loans, 'payment'));

        $breakdown = [];
        foreach ($perCategory as $catId => $sum) {
            $breakdown[] = ['category_id' => $catId, 'monthly' => round($sum / $months, 2)];
        }
        usort($breakdown, fn ($a, $b) => $b['monthly'] <=> $a['monthly']);

        $essentialMonthly = $essential / $months + $loanPayments;
        $totalMonthly = $total / $months + $loanPayments;

        usort($oneOffs, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        $events = $user->events()->whereIn('id', array_keys($eventTotals))->get(['id', 'name', 'starts_on', 'color', 'icon'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'starts_on' => $e->starts_on->toDateString(),
                'color' => $e->color,
                'icon' => $e->icon,
                'amount' => round($eventTotals[$e->id], 2),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        return [
            'months_counted' => $months,
            'essential' => round($essentialMonthly, 2),
            'total' => round($totalMonthly, 2),
            'discretionary' => round(max(0, $totalMonthly - $essentialMonthly), 2),
            'essential_share' => $totalMonthly > 0 ? round($essentialMonthly / $totalMonthly * 100, 1) : null,
            'loan_payments' => round($loanPayments, 2),
            'loans' => $loans,
            // koľko z „výdavkov" v skutočnosti odišlo do investícií
            'savings_excluded' => round($savingsFlow / $months, 2),
            // jednorazovky vyňaté z priemeru + koľko by pridali, keby sa rátali
            'one_offs' => $oneOffs,
            'one_off_events' => $events,
            'one_off_monthly' => round($oneOffTotal / $months, 2),
            'breakdown' => array_slice($breakdown, 0, 8),
            'has_data' => $months >= 2 && $totalMonthly > 0,
        ];
    }

    /**
     * Míľniky — rezerva sa nestavia naraz. Prvý stupeň zvládne drvivú väčšinu
     * nepríjemností a je na dosah aj za pár mesiacov.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function milestones(float $essential, float $months, float $held): array
    {
        $steps = [
            [
                'key' => 'buffer',
                'label' => 'Nárazník',
                'amount' => round(max(1_000, $essential * 0.5), 2),
                'note' => 'Na výdavkový náraz — pokazenú práčku, zub, pneumatiky. Pol mesiaca výdavkov, aspoň 1 000 €.',
            ],
            [
                'key' => 'target',
                'label' => 'Tvoj cieľ',
                'amount' => round($essential * $months, 2),
                'note' => 'Na výpadok príjmu — '.$this->monthsLabel($months).' nevyhnutných výdavkov.',
            ],
        ];

        foreach ($steps as $i => $step) {
            $steps[$i]['reached'] = $held >= $step['amount'];
            $steps[$i]['progress_pct'] = $step['amount'] > 0 ? round(min(100, $held / $step['amount'] * 100), 1) : 100;
            $steps[$i]['missing'] = round(max(0, $step['amount'] - $held), 2);
        }

        return $steps;
    }

    protected function monthsLabel(float $months): string
    {
        $n = rtrim(rtrim(number_format($months, 1, ',', ''), '0'), ',');

        return $n.' '.match (true) {
            $months == 1 => 'mesiac',
            $months >= 2 && $months <= 4 && floor($months) == $months => 'mesiace',
            default => 'mesiacov',
        };
    }

    /** Koľko rezervy má používateľ podľa zvoleného zdroja. */
    protected function heldReserve(User $user, array $profile, array $expenses): float
    {
        // vyhradený účet je len na rezervu — tam sa ciele neodrátavajú
        if ($profile['source'] === 'account' && $profile['account_id']) {
            return (float) $user->accounts()->whereKey($profile['account_id'])->sum('balance');
        }

        // peniaze nasporené na ciele (dovolenka, notebook…) ležia na tých
        // istých účtoch, ale na núdzu nie sú — inak by sa rátali dvakrát
        $cash = $this->finance->cash($user) - $this->goalSavings($user);

        return max(0, $profile['source'] === 'cash_minus_month' ? $cash - $expenses['total'] : $cash);
    }

    /** Súčet nasporeného na sporiace ciele. */
    protected function goalSavings(User $user): float
    {
        return round((float) $user->goals()->sum('saved_amount'), 2);
    }

    /**
     * Id kategórií považovaných za nevyhnutné — buď vlastný výber, alebo
     * východiskový zoznam podľa názvu kategórie či jej skupiny.
     *
     * @param  array<string, mixed>  $profile
     * @return array<int, int>
     */
    public function essentialCategoryIds(User $user, array $profile): array
    {
        if ($profile['essential_category_ids'] !== null) {
            return $profile['essential_category_ids'];
        }

        $categories = $user->categories()->where('type', 'expense')->get(['id', 'name', 'parent_id']);
        $nameById = $categories->pluck('name', 'id');

        return $categories
            ->filter(function ($c) use ($nameById) {
                $own = mb_strtolower($c->name);
                $parent = $c->parent_id ? mb_strtolower((string) $nameById[$c->parent_id] ?? '') : '';

                return in_array($own, self::DEFAULT_ESSENTIAL, true) || in_array($parent, self::DEFAULT_ESSENTIAL, true);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** Počet mesiacov v okne, v ktorých bol nejaký pohyb. */
    protected function countedMonths(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $user->transactions()->analyzed()
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->selectRaw('count(distinct '.Transaction::yearMonth().') as c')
            ->value('c') ?? 0;
    }

    /** @return array<int, array<string, string>> */
    protected function sources(): array
    {
        return [
            [
                'label' => __('gros.server.source_vanguard'),
                'url' => 'https://investor.vanguard.com/investor-resources-education/emergency-fund',
            ],
            [
                'label' => __('gros.server.source_unemployment'),
                'url' => 'https://www.podnikajte.sk/socialne-a-zdravotne-odvody/davka-v-nezamestnanosti-od-2026',
            ],
        ];
    }

    /** @param array<int, string> $allowed */
    protected function pick(?string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
