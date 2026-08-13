<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\EmergencyFundService;
use App\Services\ExpenseClassifier;
use App\Services\FinanceService;
use App\Services\FinancialProfileService;
use App\Services\PortfolioAnalyticsService;
use Carbon\CarbonImmutable;

/**
 * Nástroje, cez ktoré sa asistent dostane k dátam používateľa.
 *
 * Zámerne sú to úzke funkcie a nie jeden veľký výpis: model si vypýta presne
 * to, čo na otázku potrebuje, a odpoveď vie oprieť o konkrétne transakcie
 * namiesto dohadov. Všetko je len na čítanie a vždy zúžené na jedného
 * používateľa — asistent nemá ako niečo zmeniť ani vidieť cudzie dáta.
 */
class FinanceToolkit
{
    public function __construct(
        protected FinanceService $finance,
        protected FinancialProfileService $profiles,
        protected PortfolioAnalyticsService $portfolio,
        protected EmergencyFundService $reserve,
        protected ExpenseClassifier $classifier,
    ) {}

    /**
     * Definície nástrojov pre model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $period = [
            'from' => ['type' => 'string', 'description' => 'Začiatok obdobia, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'description' => 'Koniec obdobia vrátane, YYYY-MM-DD.'],
        ];

        return [
            $this->tool('spending_summary', 'Príjmy, výdavky, čistý tok a miera úspor za obdobie, plus najväčšie výdavkové kategórie. Použi na otázky typu „koľko som minul".', [
                'type' => 'object',
                'properties' => $period,
                'required' => ['from', 'to'],
            ]),
            $this->tool('compare_periods', 'Porovná dve obdobia po kategóriách a vráti, ktoré kategórie narástli alebo klesli najviac. Toto je hlavný nástroj na otázku „prečo som minul viac než minule".', [
                'type' => 'object',
                'properties' => [
                    'a_from' => ['type' => 'string', 'description' => 'Začiatok prvého (novšieho) obdobia, YYYY-MM-DD.'],
                    'a_to' => ['type' => 'string', 'description' => 'Koniec prvého obdobia, YYYY-MM-DD.'],
                    'b_from' => ['type' => 'string', 'description' => 'Začiatok druhého (staršieho) obdobia, YYYY-MM-DD.'],
                    'b_to' => ['type' => 'string', 'description' => 'Koniec druhého obdobia, YYYY-MM-DD.'],
                ],
                'required' => ['a_from', 'a_to', 'b_from', 'b_to'],
            ]),
            $this->tool('list_transactions', 'Konkrétne transakcie za obdobie. Použi, keď treba odpoveď podložiť konkrétnymi položkami — napríklad po tom, čo compare_periods ukáže, ktorá kategória narástla.', [
                'type' => 'object',
                'properties' => $period + [
                    'category_name' => ['type' => 'string', 'description' => 'Voliteľné: názov kategórie alebo jej časť.'],
                    'search' => ['type' => 'string', 'description' => 'Voliteľné: hľadaný text v poznámke.'],
                    'min_amount' => ['type' => 'number', 'description' => 'Voliteľné: len transakcie od tejto sumy.'],
                    'type' => ['type' => 'string', 'enum' => ['income', 'expense'], 'description' => 'Voliteľné: typ transakcie.'],
                    'limit' => ['type' => 'integer', 'description' => 'Koľko najväčších vrátiť, max 50. Predvolene 20.'],
                ],
                'required' => ['from', 'to'],
            ]),
            $this->tool('monthly_trend', 'Príjmy, výdavky a čistý tok po mesiacoch za posledných N mesiacov. Na otázky o vývoji a trendoch.', [
                'type' => 'object',
                'properties' => [
                    'months' => ['type' => 'integer', 'description' => 'Počet mesiacov dozadu, max 24. Predvolene 12.'],
                ],
            ]),
            $this->tool('financial_overview', 'Celkový obraz: účty, hotovosť, portfólio, dlhy, čisté imanie, meraný príjem a výdavky, stav núdzovej rezervy. Použi na širšie otázky o tom, ako na tom používateľ je.', [
                'type' => 'object', 'properties' => (object) [],
            ]),
            $this->tool('investment_portfolio', 'Zloženie portfólia, hodnota, ročný výnos (XIRR), volatilita, najväčší prepad a koncentrácia.', [
                'type' => 'object', 'properties' => (object) [],
            ]),
            $this->tool('recurring_costs', 'Predplatné a splátky úverov — pravidelné mesačné náklady.', [
                'type' => 'object', 'properties' => (object) [],
            ]),
        ];
    }

    /** @param array<string, mixed> $args */
    public function call(User $user, string $name, array $args): array
    {
        return match ($name) {
            'spending_summary' => $this->spendingSummary($user, $args),
            'compare_periods' => $this->comparePeriods($user, $args),
            'list_transactions' => $this->listTransactions($user, $args),
            'monthly_trend' => $this->monthlyTrend($user, $args),
            'financial_overview' => $this->financialOverview($user),
            'investment_portfolio' => $this->investmentPortfolio($user),
            'recurring_costs' => $this->recurringCosts($user),
            default => ['error' => "Neznámy nástroj: $name"],
        };
    }

    // ── Jednotlivé nástroje ─────────────────────────────────────────────

    protected function spendingSummary(User $user, array $args): array
    {
        [$from, $to] = $this->range($args['from'] ?? null, $args['to'] ?? null);

        $rows = $this->expenses($user, $from, $to);
        $income = (float) $user->transactions()->analyzed()
            ->where('type', 'income')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('amount');
        $expense = (float) $rows->sum(fn ($t) => (float) $t->net_amount);

        return [
            'obdobie' => "$from – $to",
            'prijem' => round($income, 2),
            'vydavky' => round($expense, 2),
            'cisty_tok' => round($income - $expense, 2),
            'miera_uspor_pct' => $income > 0 ? round(($income - $expense) / $income * 100, 1) : null,
            'pocet_transakcii' => $rows->count(),
            'najvacsie_kategorie' => $this->byCategory($user, $rows)->take(8)->values()->all(),
        ];
    }

    protected function comparePeriods(User $user, array $args): array
    {
        [$aFrom, $aTo] = $this->range($args['a_from'] ?? null, $args['a_to'] ?? null);
        [$bFrom, $bTo] = $this->range($args['b_from'] ?? null, $args['b_to'] ?? null);

        $a = $this->byCategory($user, $this->expenses($user, $aFrom, $aTo))->keyBy('kategoria');
        $b = $this->byCategory($user, $this->expenses($user, $bFrom, $bTo))->keyBy('kategoria');

        $changes = [];
        foreach ($a->keys()->merge($b->keys())->unique() as $name) {
            $now = (float) ($a[$name]['suma'] ?? 0);
            $then = (float) ($b[$name]['suma'] ?? 0);
            if (abs($now - $then) < 1) {
                continue;
            }
            $changes[] = [
                'kategoria' => $name,
                'teraz' => round($now, 2),
                'predtym' => round($then, 2),
                'rozdiel' => round($now - $then, 2),
                'zmena_pct' => $then > 0 ? round(($now - $then) / $then * 100, 1) : null,
            ];
        }
        usort($changes, fn ($x, $y) => abs($y['rozdiel']) <=> abs($x['rozdiel']));

        $aTotal = (float) $this->expenses($user, $aFrom, $aTo)->sum(fn ($t) => (float) $t->net_amount);
        $bTotal = (float) $this->expenses($user, $bFrom, $bTo)->sum(fn ($t) => (float) $t->net_amount);

        return [
            'obdobie_a' => "$aFrom – $aTo",
            'obdobie_b' => "$bFrom – $bTo",
            'vydavky_a' => round($aTotal, 2),
            'vydavky_b' => round($bTotal, 2),
            'rozdiel_spolu' => round($aTotal - $bTotal, 2),
            'zmeny_podla_kategorie' => array_slice($changes, 0, 12),
        ];
    }

    protected function listTransactions(User $user, array $args): array
    {
        [$from, $to] = $this->range($args['from'] ?? null, $args['to'] ?? null);
        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));

        $q = $this->classifier->excludeSavings($user->transactions()->analyzed(), $user)
            ->with('category:id,name')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);

        if (! empty($args['type'])) {
            $q->where('type', $args['type']);
        } else {
            $q->where('type', '!=', 'transfer');
        }
        if (! empty($args['search'])) {
            $q->where('note', 'like', '%'.$args['search'].'%');
        }
        if (! empty($args['min_amount'])) {
            $q->where('amount', '>=', (float) $args['min_amount']);
        }
        if (! empty($args['category_name'])) {
            $ids = $user->categories()->where('name', 'like', '%'.$args['category_name'].'%')->pluck('id');
            $childIds = $user->categories()->whereIn('parent_id', $ids)->pluck('id');
            $q->whereIn('category_id', $ids->merge($childIds));
        }

        return [
            'obdobie' => "$from – $to",
            'transakcie' => $q->orderByDesc('amount')->limit($limit)->get()
                ->map(fn (Transaction $t) => [
                    'datum' => $t->date->toDateString(),
                    'suma' => round((float) $t->net_amount, 2),
                    'typ' => $t->type,
                    'kategoria' => $t->category?->name,
                    'poznamka' => $t->note,
                ])->all(),
        ];
    }

    protected function monthlyTrend(User $user, array $args): array
    {
        $months = min(24, max(1, (int) ($args['months'] ?? 12)));

        return [
            'mesiace' => app(AnalyticsService::class)->monthlySeries($user, $months)
                ->map(fn ($m) => [
                    'mesiac' => $m['ym'],
                    'prijem' => round($m['income'], 2),
                    'vydavky' => round($m['expense'], 2),
                    'cisty_tok' => round($m['net'], 2),
                ])->all(),
        ];
    }

    protected function financialOverview(User $user): array
    {
        $profile = $this->profiles->forUser($user);
        $reserve = $this->reserve->forUser($user);

        return [
            'majetok' => $profile['assets'],
            'merany_mesacny_tok' => [
                'prijem' => $profile['measured']['income'],
                'vydavky_hrube' => $profile['measured']['expense'],
                'vydavky_bezne' => $profile['measured']['recurring_expense'],
                'z_toho_poslane_do_portfolia' => $profile['measured']['savings_flow'],
                'z_toho_jednorazovky' => $profile['measured']['one_off'],
                'ostava' => $profile['measured']['recurring_savings'],
                'miera_uspor_pct' => $profile['measured']['recurring_savings_rate'],
                'meranych_mesiacov' => $profile['measured']['months'],
            ],
            'ucty' => $user->accounts()->get(['name', 'type', 'balance'])
                ->map(fn ($a) => ['nazov' => $a->name, 'typ' => $a->type, 'zostatok' => round((float) $a->balance, 2)])->all(),
            'nudzova_rezerva' => [
                'ciel' => $reserve['target'],
                'mas' => $reserve['held'],
                'pokryje_mesiacov' => $reserve['covered_months'],
                'chyba' => $reserve['gap'],
                'odporucanych_mesiacov' => $reserve['months']['recommended'],
            ],
        ];
    }

    protected function investmentPortfolio(User $user): array
    {
        $a = $this->portfolio->forUser($user);
        if (! ($a['ok'] ?? false)) {
            return ['poznamka' => $a['reason'] ?? 'Žiadne investície.'];
        }

        return [
            'hodnota' => $a['value'],
            'vlozene' => $a['invested'],
            'zisk_spolu' => $a['profit_total'],
            'rocny_vynos_xirr_pct' => $a['xirr'],
            'volatilita_pct' => $a['risk']['volatility'] ?? null,
            'najvacsi_prepad_pct' => $a['risk']['max_drawdown'] ?? null,
            'rozlozenie' => $a['allocation']['by_kind'],
            'najvacsia_pozicia_pct' => $a['allocation']['top_weight'],
            'efektivny_pocet_pozicii' => $a['allocation']['effective_positions'],
            'pozicie' => $user->investments()->get()
                ->map(fn ($i) => [
                    'ticker' => $i->ticker,
                    'nazov' => $i->name,
                    'druh' => $i->kind,
                    'hodnota' => round($i->value, 2),
                    'zisk' => round($i->gain, 2),
                ])->all(),
        ];
    }

    protected function recurringCosts(User $user): array
    {
        return [
            'predplatne' => $user->subscriptions()->get()
                ->map(fn ($s) => [
                    'nazov' => $s->name,
                    'suma' => round((float) $s->amount, 2),
                    'cyklus' => $s->cycle,
                    'mesacne' => round($s->monthly_amount, 2),
                ])->all(),
            'uvery' => $user->loans()->get()
                ->map(fn ($l) => [
                    'nazov' => $l->name,
                    'druh' => $l->kind === 'owe' ? 'dlžím' : 'požičal som',
                    'zostatok' => round((float) $l->balance, 2),
                    'splatka' => round((float) $l->payment, 2),
                    'urok_pct' => round((float) $l->rate, 2),
                ])->all(),
        ];
    }

    // ── Pomocné ─────────────────────────────────────────────────────────

    protected function expenses(User $user, string $from, string $to)
    {
        return $this->classifier->excludeSavings($user->transactions()->analyzed(), $user)
            ->with('category:id,name,parent_id')
            ->where('type', 'expense')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->get();
    }

    /** Súčty po kategórii, zrolované do skupín. */
    protected function byCategory(User $user, $rows)
    {
        $parents = $user->categories()->pluck('name', 'id');

        return $rows->groupBy(function ($t) use ($parents) {
            if (! $t->category) {
                return 'Bez kategórie';
            }

            return $t->category->parent_id ? ($parents[$t->category->parent_id] ?? $t->category->name) : $t->category->name;
        })->map(fn ($group, $name) => [
            'kategoria' => $name,
            'suma' => round($group->sum(fn ($t) => (float) $t->net_amount), 2),
            'pocet' => $group->count(),
        ])->sortByDesc('suma')->values();
    }

    /** @return array{0: string, 1: string} */
    protected function range(?string $from, ?string $to): array
    {
        $f = $from ? CarbonImmutable::parse($from) : CarbonImmutable::today()->startOfMonth();
        $t = $to ? CarbonImmutable::parse($to) : CarbonImmutable::today();

        return [$f->toDateString(), $t->toDateString()];
    }

    protected function tool(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $description, 'parameters' => $parameters],
        ];
    }
}
