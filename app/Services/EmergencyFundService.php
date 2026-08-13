<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Osobný núdzový fond — koľko rezervy má zmysel držať práve tebe.
 *
 * Odporúčania nie sú vymyslené, vychádzajú z troch zdrojov:
 *  - Vanguard rozlišuje dva druhy nárazu: výdavkový (menší, častý) a
 *    príjmový (výpadok práce). Na prvý stačí pol mesiaca výdavkov, na druhý
 *    tri až šesť mesiacov.
 *  - JPMorgan Chase Institute meral, koľko likvidity treba na súbežný
 *    prepad príjmu a skok výdavkov — vyšlo im ~6 týždňov čistého príjmu.
 *  - Na Slovensku podporné obdobie trvá 6 mesiacov, ale od 1. 1. 2026 dávka
 *    klesá z 50 % DVZ (1.–3. mesiac) na 40 %, 30 % a 20 %. Nárok vzniká pri
 *    730 dňoch poistenia za posledné 4 roky.
 *
 * Základ sú tri mesiace nevyhnutných výdavkov; rizikové faktory ho posúvajú.
 * Časť faktorov sa meria priamo z transakcií, takže odporúčanie sa hýbe samo
 * podľa toho, ako sa mení príjem a výdavky.
 */
class EmergencyFundService
{
    /** Koľko ukončených mesiacov sa berie do priemerov a merania kolísania. */
    protected const WINDOW = 12;

    /** Východiskový počet mesiacov pred rizikovými úpravami. */
    protected const BASE_MONTHS = 3;

    protected const MIN_MONTHS = 3;

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
    ];

    public function __construct(
        protected AnalyticsService $analytics,
        protected FinanceService $finance,
        protected RetirementService $retirement,
        protected PortfolioAnalyticsService $portfolio,
        protected ExpenseClassifier $classifier,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $profile = $this->profile($user);
        $expenses = $this->essentialExpenses($user, $profile);
        $income = $this->incomeStats($user);

        $months = $this->recommendMonths($profile, $expenses, $income);
        $essential = $expenses['essential'];

        $target = $essential * $months['months'];
        $held = $this->heldReserve($user, $expenses);

        return [
            'profile' => $profile,
            'expenses' => $expenses,
            'income' => $income,
            'months' => $months,
            'target' => round($target, 2),
            'held' => round($held, 2),
            'covered_months' => $essential > 0 ? round($held / $essential, 1) : null,
            'gap' => round(max(0, $target - $held), 2),
            'progress_pct' => $target > 0 ? round(min(100, $held / $target * 100), 1) : 0,
            'milestones' => $this->milestones($essential, $months['months'], $held, $profile, $expenses, $this->afterSchoolEstimate($user, $profile, $expenses)),
            'plan' => $this->fillPlan($user, max(0, $target - $held)),
            'after_school' => $this->afterSchoolEstimate($user, $profile, $expenses),
            'cost' => $this->costOfHolding($user, $target),
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

        return [
            'income_type' => $this->pick($saved['income_type'] ?? null, ['stable', 'variable', 'self_employed', 'mixed', 'student'], 'variable'),
            'graduation_year' => isset($saved['graduation_year']) ? (int) $saved['graduation_year'] : null,
            'post_graduation_expenses' => isset($saved['post_graduation_expenses']) ? (float) $saved['post_graduation_expenses'] : null,
            // odkiaľ sa berie odhad nájmu po škole
            'after_school_city' => $saved['after_school_city'] ?? null,
            'after_school_size' => isset($saved['after_school_size']) ? (string) $saved['after_school_size'] : null,
            // 1.0 = bývaš sám, 0.5 = delíš sa s niekým
            'after_school_share' => isset($saved['after_school_share']) ? (float) $saved['after_school_share'] : 1.0,
            'household' => $this->pick($saved['household'] ?? null, ['single', 'dual_income', 'single_income_couple', 'dependents'], 'single'),
            'unemployment_benefit' => (bool) ($saved['unemployment_benefit'] ?? true),
            'health_risk' => (bool) ($saved['health_risk'] ?? false),
            'source' => $this->pick($saved['source'] ?? null, ['all_cash', 'account', 'cash_minus_month'], 'all_cash'),
            'account_id' => isset($saved['account_id']) ? (int) $saved['account_id'] : null,
            'essential_category_ids' => isset($saved['essential_category_ids']) && is_array($saved['essential_category_ids'])
                ? array_map('intval', $saved['essential_category_ids'])
                : null,
            // označené jednorazovky, o ktorých používateľ povedal, že sa opakujú
            'recurring_transaction_ids' => isset($saved['recurring_transaction_ids']) && is_array($saved['recurring_transaction_ids'])
                ? array_map('intval', $saved['recurring_transaction_ids'])
                : [],
            'months_override' => isset($saved['months_override']) ? (float) $saved['months_override'] : null,
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
            ->get(['id', 'category_id', 'date', 'note', 'amount', 'refunded_amount']);

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

        foreach ($transactions as $t) {
            $amount = (float) $t->net_amount;
            $catId = $t->category_id === null ? null : (int) $t->category_id;

            // sporenie a investovanie nie je spotreba — v kríze proste ustane
            if ($catId !== null && in_array($catId, $savingsIds, true)) {
                $savingsFlow += $amount;

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
            'one_off_monthly' => round($oneOffTotal / $months, 2),
            'breakdown' => array_slice($breakdown, 0, 8),
            'has_data' => $months >= 2 && $totalMonthly > 0,
        ];
    }

    /**
     * Odhad mesačných nákladov po škole: to, čo míňaš dnes bez bývania,
     * plus nájom podľa toho, kam sa chystáš.
     *
     * Väčšina študentov bývanie neplatí, takže dnešné výdavky nehovoria
     * o živote po škole nič. A keďže do dôchodku sa ide dávno po škole,
     * práve toto číslo — nie to dnešné — patrí do dôchodkovej projekcie.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function afterSchoolEstimate(User $user, array $profile, array $expenses): array
    {
        $ref = config('gros.reference.rent');

        $rent = null;
        $basis = null;
        if ($profile['after_school_city'] && isset($ref['by_city'][$profile['after_school_city']])) {
            $rent = (float) $ref['by_city'][$profile['after_school_city']]['rent'];
            $basis = $ref['by_city'][$profile['after_school_city']]['label'];
        } elseif ($profile['after_school_size'] && isset($ref['by_size'][$profile['after_school_size']])) {
            $rent = (float) $ref['by_size'][$profile['after_school_size']]['rent'];
            $basis = $ref['by_size'][$profile['after_school_size']]['label'];
        }

        // podiel bývania sa musí odrátať, inak by sa nájom počítal dvakrát
        $housingIds = $this->housingCategoryIds($user);
        $housingNow = 0.0;
        foreach ($expenses['breakdown'] as $row) {
            if (in_array($row['category_id'], $housingIds, true)) {
                $housingNow += $row['monthly'];
            }
        }

        $withoutHousing = max(0, $expenses['total'] - $housingNow);
        $share = $profile['after_school_share'];

        return [
            'available' => $rent !== null,
            'rent' => $rent === null ? null : round($rent * $share, 2),
            'rent_full' => $rent,
            'rent_share' => $share,
            'basis' => $basis,
            'current_without_housing' => round($withoutHousing, 2),
            'housing_now' => round($housingNow, 2),
            'estimate' => $rent === null ? null : round($withoutHousing + $rent * $share, 2),
            'source' => $ref['source'],
            'url' => $ref['url'],
            'cities' => $ref['by_city'],
            'sizes' => $ref['by_size'],
            'sr_average' => $ref['sr_average'],
            'income' => config('gros.reference.graduate_income'),
        ];
    }

    /** @return array<int, int> */
    protected function housingCategoryIds(User $user): array
    {
        $categories = $user->categories()->where('type', 'expense')->get(['id', 'name', 'parent_id']);
        $nameById = $categories->pluck('name', 'id');

        return $categories
            ->filter(function ($c) use ($nameById) {
                $own = mb_strtolower($c->name);
                $parent = $c->parent_id ? mb_strtolower((string) ($nameById[$c->parent_id] ?? '')) : '';

                return $own === 'bývanie' || $parent === 'bývanie';
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Kolísanie príjmu — meria sa priamo z transakcií, takže odporúčanie
     * reaguje na to, ako sa tvoj príjem naozaj správa.
     *
     * @return array<string, mixed>
     */
    public function incomeStats(User $user): array
    {
        $currentYm = CarbonImmutable::today()->format('Y-m');
        $months = $this->analytics->monthlySeries($user, self::WINDOW + 1)
            ->reject(fn ($m) => $m['ym'] === $currentYm)
            ->filter(fn ($m) => $m['income'] > 0 || $m['expense'] > 0)
            ->values();

        $incomes = $months->pluck('income')->map(fn ($v) => (float) $v)->all();
        $n = count($incomes);
        if ($n < 3) {
            return ['months' => $n, 'average' => 0.0, 'volatility' => null, 'is_volatile' => false, 'worst' => null];
        }

        $mean = array_sum($incomes) / $n;
        $variance = 0.0;
        foreach ($incomes as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $sd = sqrt($variance / ($n - 1));

        // variačný koeficient: smerodajná odchýlka ako podiel priemeru
        $cv = $mean > 0 ? $sd / $mean : null;

        return [
            'months' => $n,
            'average' => round($mean, 2),
            'volatility' => $cv === null ? null : round($cv * 100, 1),
            'is_volatile' => $cv !== null && $cv > 0.25,
            'worst' => round(min($incomes), 2),
        ];
    }

    /**
     * Odporúčaný počet mesiacov + rozpis, prečo práve toľko.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $expenses
     * @param  array<string, mixed>  $income
     * @return array<string, mixed>
     */
    public function recommendMonths(array $profile, array $expenses, array $income): array
    {
        $factors = [[
            'key' => 'base',
            'label' => 'Základ',
            'delta' => self::BASE_MONTHS,
            'note' => 'Tri mesiace nevyhnutných výdavkov — spodná hranica bežného odporúčania (Vanguard, CFPB).',
        ]];

        $factors[] = match ($profile['income_type']) {
            'student' => ['key' => 'income_type', 'label' => 'Študent s prácou popri škole', 'delta' => 0, 'note' => 'Brigáda sa nahrádza v týždňoch, nie mesiacoch, a nevyhnutné výdavky sú nízke. Model „N mesiacov bez práce" sem nesedí — dôležitejší je nárazník a rezerva na prechod po škole.'],
            'self_employed' => ['key' => 'income_type', 'label' => 'SZČO / živnosť', 'delta' => 3, 'note' => 'Bez zamestnaneckého poistenia v nezamestnanosti a s nepravidelnými platbami sa odporúča 6–12 mesiacov.'],
            'variable' => ['key' => 'income_type', 'label' => 'Premenlivý príjem', 'delta' => 1, 'note' => 'Provízie a bonusy kolíšu — istý je len základ, preto mesiac navyše.'],
            'mixed' => ['key' => 'income_type', 'label' => 'Zamestnanie + živnosť', 'delta' => 0, 'note' => 'Dva zdroje príjmu zvyknú nevypadnúť naraz, čo riziko rozkladá.'],
            default => ['key' => 'income_type', 'label' => 'Stabilný zamestnanecký príjem', 'delta' => 0, 'note' => 'Pravidelná výplata bez výkyvov — základ stačí.'],
        };

        $factors[] = match ($profile['household']) {
            'dual_income' => ['key' => 'household', 'label' => 'Dva príjmy v domácnosti', 'delta' => -1, 'note' => 'Výpadok jedného príjmu neznamená výpadok celého rozpočtu.'],
            'single_income_couple' => ['key' => 'household', 'label' => 'Domácnosť na jednom príjme', 'delta' => 1, 'note' => 'Celá domácnosť visí na tvojom príjme.'],
            'dependents' => ['key' => 'household', 'label' => 'Závislé osoby', 'delta' => 2, 'note' => 'Fixné náklady na deti sa nedajú zoškrtať tak, ako vlastné.'],
            default => ['key' => 'household', 'label' => 'Jednočlenná domácnosť', 'delta' => 0, 'note' => 'V kríze vieš výdavky zrezať rýchlo a hlboko.'],
        };

        if ($profile['income_type'] === 'student') {
            // Na dohode o brigádnickej práci študentov sa poistenie v nezamestnanosti
            // neplatí — nárok teda nie je a ani sa nenasporia dni na budúci. Prirážka
            // by tu však bola za nesprávne riziko; tú dieru rieši míľnik po škole.
            $factors[] = [
                'key' => 'benefit',
                'label' => 'Bez poistenia v nezamestnanosti',
                'delta' => 0,
                'note' => 'Na dohode o brigádnickej práci študentov platíš len dôchodkové poistenie (4 % starobné, 3 % invalidné). Nárok na dávku nemáš a ani si nesporíš 730 dní potrebných do budúcna — s tým počíta míľnik na prechod po škole.',
            ];
        } elseif ($profile['unemployment_benefit'] && $profile['income_type'] !== 'self_employed') {
            $factors[] = [
                'key' => 'benefit',
                'label' => 'Nárok na dávku v nezamestnanosti',
                'delta' => -1,
                'note' => 'Podporné obdobie je 6 mesiacov, ale od 1. 1. 2026 dávka klesá z 50 % DVZ na 40, 30 a napokon 20 %. Kryje časť výpadku, nie celý.',
            ];
        } else {
            $factors[] = [
                'key' => 'benefit',
                'label' => 'Bez nároku na dávku',
                'delta' => 1,
                'note' => 'Bez podpory musí prvé mesiace bez príjmu pokryť výlučne rezerva.',
            ];
        }

        if ($income['is_volatile']) {
            $factors[] = [
                'key' => 'volatility',
                'label' => 'Namerané kolísanie príjmu',
                'delta' => 1,
                'note' => 'Tvoj príjem kolíše o '.$income['volatility'].' % — merané z transakcií za posledný rok.',
            ];
        }

        if (($expenses['essential_share'] ?? 0) > 70) {
            $factors[] = [
                'key' => 'fixed_share',
                'label' => 'Vysoký podiel nevyhnutných výdavkov',
                'delta' => 1,
                'note' => 'Nevyhnutné výdavky tvoria '.$expenses['essential_share'].' % rozpočtu — v kríze niet čo škrtať.',
            ];
        }

        if ($profile['health_risk']) {
            $factors[] = [
                'key' => 'health',
                'label' => 'Zdravotné riziko',
                'delta' => 1,
                'note' => 'Dlhšia práceneschopnosť znamená nižší príjem a zároveň vyššie výdavky.',
            ];
        }

        $raw = array_sum(array_column($factors, 'delta'));
        $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, $raw));

        return [
            'months' => $profile['months_override'] ?? $months,
            'recommended' => $months,
            'raw' => $raw,
            'clamped' => $raw !== $months,
            'overridden' => $profile['months_override'] !== null,
            'factors' => $factors,
        ];
    }

    /**
     * Míľniky — rezerva sa nestavia naraz. Prvý stupeň zvládne drvivú väčšinu
     * nepríjemností a je na dosah aj za pár mesiacov.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function milestones(float $essential, float $months, float $held, array $profile, array $expenses, array $afterSchool): array
    {
        $steps = [
            [
                'key' => 'buffer',
                'label' => 'Nárazník',
                'amount' => round(max(1_000, $essential * 0.5), 2),
                'note' => 'Pokryje výdavkový náraz — pokazenú práčku, zub, pneumatiky. Vanguard nameral, že už 2 000 $ odložených zdvihne pocit finančnej istoty o 21 %.',
            ],
            [
                'key' => 'base',
                'label' => 'Základ',
                'amount' => round($essential * 3, 2),
                'note' => 'Tri mesiace nevyhnutných výdavkov — spodná hranica na výpadok príjmu.',
            ],
            [
                'key' => 'target',
                'label' => 'Tvoj cieľ',
                'amount' => round($essential * $months, 2),
                'note' => 'Tvoj rizikový profil po započítaní príjmu, domácnosti a dávky.',
            ],
        ];

        if ($profile['income_type'] === 'student') {
            $steps[] = $this->graduationStep($profile, $expenses, $afterSchool);
        }

        foreach ($steps as $i => $step) {
            $steps[$i]['reached'] = $held >= $step['amount'];
            $steps[$i]['progress_pct'] = $step['amount'] > 0 ? round(min(100, $held / $step['amount'] * 100), 1) : 100;
            $steps[$i]['missing'] = round(max(0, $step['amount'] - $held), 2);
        }

        return $steps;
    }

    /**
     * Prechod po škole — obdobie, na ktoré nikto nesporí a pritom je to
     * najnechránenejší úsek života: medzi školou a prvou výplatou nie je
     * príjem, pribudne nájom s depozitom a nárok na dávku stále nie je,
     * lebo študentská práca nesporí 730 dní poistenia.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $expenses
     * @return array<string, mixed>
     */
    protected function graduationStep(array $profile, array $expenses, array $afterSchool): array
    {
        // po škole sa žije drahšie než počas nej — ak si používateľ vlastný
        // odhad nezadal, berieme aspoň jeho súčasné celkové výdavky
        // vlastný odhad > odhad z Rent Indexu > dnešné výdavky (najhorší zdroj)
        $monthly = $profile['post_graduation_expenses']
            ?? $afterSchool['estimate']
            ?? max($expenses['total'], $expenses['essential']);
        $year = $profile['graduation_year'];

        return [
            'key' => 'graduation',
            'label' => $year ? "Prechod po škole ($year)" : 'Prechod po škole',
            'amount' => round($monthly * 3, 2),
            'note' => 'Tri mesiace života po škole: hľadanie práce, depozit na byt a sťahovanie. Na dávku v nezamestnanosti vtedy ešte nárok nebudeš mať — 730 dní poistenia sa zo študentskej dohody nenasporí.',
            'monthly_basis' => round($monthly, 2),
            'is_estimate' => $profile['post_graduation_expenses'] === null,
            'from_rent_index' => $profile['post_graduation_expenses'] === null && $afterSchool['estimate'] !== null,
        ];
    }

    /**
     * Ako rýchlo sa rezerva dá naplniť pri skutočnej miere úspor — a čo to
     * urobí s investovaním. Zdržanie je krátke, práve preto sa oplatí.
     *
     * @return array<string, mixed>
     */
    protected function fillPlan(User $user, float $gap): array
    {
        $currentYm = CarbonImmutable::today()->format('Y-m');
        $months = $this->analytics->monthlySeries($user, self::WINDOW + 1)
            ->reject(fn ($m) => $m['ym'] === $currentYm)
            ->filter(fn ($m) => $m['income'] > 0 || $m['expense'] > 0)
            ->values();

        $surplus = $months->count() > 0 ? (float) $months->avg('net') : 0.0;

        $options = [];
        if ($gap > 0 && $surplus > 0) {
            foreach ([100, 75, 50] as $share) {
                $monthly = $surplus * $share / 100;
                $options[] = [
                    'share' => $share,
                    'monthly' => round($monthly, 2),
                    'months' => (int) ceil($gap / $monthly),
                    'investing' => round($surplus - $monthly, 2),
                ];
            }
        }

        return [
            'gap' => round($gap, 2),
            'monthly_surplus' => round($surplus, 2),
            'options' => $options,
            'possible' => $gap <= 0 || $surplus > 0,
        ];
    }

    /**
     * Poctivá cena rezervy: v hotovosti nezarába, takže niečo stojí. Oproti
     * tomu stojí to, čo stojí jej absencia — nútený predaj v prepade.
     *
     * @return array<string, mixed>
     */
    protected function costOfHolding(User $user, float $target): array
    {
        $realReturn = $this->retirement->realReturnAssumption($user);
        $analytics = $this->portfolio->forUser($user);

        $drawdown = null;
        if (($analytics['ok'] ?? false) && ($analytics['risk']['ok'] ?? false)) {
            $drawdown = (float) $analytics['risk']['max_drawdown'];
        }

        $portfolioValue = (float) ($analytics['value'] ?? 0);

        return [
            'real_return' => $realReturn,
            // ušlý výnos = poistné, ktoré za rezervu platíš
            'annual_opportunity' => round($target * $realReturn / 100, 2),
            'max_drawdown' => $drawdown,
            // koľko by stál nútený predaj celého portfólia na dne prepadu
            'forced_sale_loss' => $drawdown === null ? null : round($portfolioValue * abs($drawdown) / 100, 2),
            'portfolio_value' => round($portfolioValue, 2),
        ];
    }

    /** Koľko rezervy má používateľ podľa zvoleného zdroja. */
    protected function heldReserve(User $user, array $expenses): float
    {
        $profile = $this->profile($user);

        return match ($profile['source']) {
            'account' => $profile['account_id']
                ? (float) $user->accounts()->whereKey($profile['account_id'])->sum('balance')
                : $this->finance->cash($user),
            'cash_minus_month' => max(0, $this->finance->cash($user) - $expenses['total']),
            default => $this->finance->cash($user),
        };
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
                'label' => 'Vanguard — dva druhy nárazu a suma, ktorá mení pocit istoty',
                'url' => 'https://investor.vanguard.com/investor-resources-education/emergency-fund',
            ],
            [
                'label' => 'JPMorgan Chase Institute — Weathering Volatility 2.0 (koľko likvidity treba na súbežný šok)',
                'url' => 'https://www.jpmorganchase.com/content/dam/jpmc/jpmorgan-chase-and-co/institute/pdf/institute-volatility-cash-buffer-report.pdf',
            ],
            [
                'label' => 'Dávka v nezamestnanosti od roku 2026 — klesajúca sadzba 50 → 20 % DVZ',
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
