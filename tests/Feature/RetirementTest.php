<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialProfileService;
use App\Services\MarketDataService;
use App\Services\PortfolioAnalyticsService;
use App\Services\RetirementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RetirementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->fakeMarketData();
    }

    /**
     * Deterministické „historické" dáta: 1,1 % mesačne s −15 % prepadom každé
     * štyri roky. Vychádza z toho ~9 % nominálne ročne — rádovo to isté, čo
     * dávajú skutočné akciové indexy, aby testy overovali realistický režim.
     */
    protected function fakeMarketData(): void
    {
        $timestamps = [];
        $closes = [];
        $price = 100.0;
        $start = CarbonImmutable::create(1993, 1, 1);
        for ($m = 0; $m < 400; $m++) {
            $timestamps[] = $start->addMonths($m)->timestamp;
            $closes[] = $price;
            $price *= $m % 50 === 49 ? 0.85 : 1.011;
        }

        $periods = [];
        $observations = [];
        for ($i = 0; $i < 240; $i++) {
            $periods[] = ['id' => CarbonImmutable::create(2000, 1, 1)->addMonths($i)->format('Y-m')];
            $observations[(string) $i] = [3.0];
        }

        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => ['result' => [[
                    'meta' => ['currency' => 'EUR', 'regularMarketPrice' => end($closes)],
                    'timestamp' => $timestamps,
                    'indicators' => ['quote' => [['close' => $closes]]],
                ]]],
            ]),
            'data-api.ecb.europa.eu/*' => Http::response([
                'structure' => ['dimensions' => ['observation' => [['id' => 'TIME_PERIOD', 'values' => $periods]]]],
                'dataSets' => [['series' => ['0:0:0:0:0:0' => ['observations' => $observations]]]],
            ]),
            'api.frankfurter.dev/*' => Http::response(['rates' => []]),
        ]);
    }

    public function test_benchmark_history_is_parsed_into_monthly_returns(): void
    {
        $b = app(MarketDataService::class)->benchmark('world');

        $this->assertNotNull($b);
        $this->assertSame(399, $b['months']);
        $this->assertSame('EUR', $b['currency']);
        $this->assertGreaterThan(0, $b['cagr']);
        $this->assertGreaterThan(0, $b['vol']);
    }

    public function test_dividends_are_included_via_the_adjusted_close(): void
    {
        // ceny stoja, ale adjclose rastie — čistý dividendový výnos
        $timestamps = [];
        $closes = [];
        $adjusted = [];
        $adj = 100.0;
        for ($m = 0; $m < 120; $m++) {
            $timestamps[] = CarbonImmutable::create(2010, 1, 1)->addMonths($m)->timestamp;
            $closes[] = 100.0;
            $adjusted[] = $adj;
            $adj *= 1.005;
        }

        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => ['result' => [[
                    'meta' => ['currency' => 'USD'],
                    'timestamp' => $timestamps,
                    'indicators' => [
                        'quote' => [['close' => $closes]],
                        'adjclose' => [['adjclose' => $adjusted]],
                    ],
                ]]],
            ]),
        ]);

        $b = app(MarketDataService::class)->benchmark('acwi');

        // bez adjclose by výnos vyšiel nula
        $this->assertGreaterThan(0.05, $b['cagr']);
    }

    public function test_the_default_plan_uses_the_longest_history_and_a_matching_haircut(): void
    {
        $params = app(RetirementService::class)->resolveParams(User::factory()->create());

        $this->assertSame(RetirementService::DEFAULT_ENGINE, $params['engine']);
        $this->assertSame(RetirementService::DEFAULT_HAIRCUT, $params['haircut']);

        // predvolený motor musí byť naozaj ten najdlhší z ponuky
        $since = array_column(MarketDataService::BENCHMARKS, 'since', null);
        $keys = array_keys(MarketDataService::BENCHMARKS);
        $longest = $keys[array_search(min($since), $since, true)];
        $this->assertSame($longest, RetirementService::DEFAULT_ENGINE);
    }

    public function test_the_default_haircut_is_actually_applied_to_the_projection(): void
    {
        $svc = app(RetirementService::class);
        $user = User::factory()->create();
        $args = ['year' => 2065, 'monthly' => 100, 'inflation' => 3.11];

        $default = $svc->project($user, 10_000, $args);
        $raw = $svc->project($user, 10_000, $args + ['haircut' => 0]);

        // zrážka je v ťahu spolu s poplatkami a naozaj znižuje výnos
        $this->assertSame(round(0.25 + RetirementService::DEFAULT_HAIRCUT, 2), $default['engine']['drag']);
        $this->assertLessThan($raw['engine']['net_cagr'], $default['engine']['net_cagr']);
        $this->assertLessThan($default['engine']['cagr'], $default['engine']['net_cagr']);

        // a premietne sa až do konečnej sumy, nie len do popisku
        $this->assertLessThan($raw['final']['real']['p50'], $default['final']['real']['p50']);
    }

    public function test_short_history_engines_are_marked(): void
    {
        $m = app(MarketDataService::class);

        // fake dáta majú 400 mesiacov ≈ 33 rokov, takže krátke nie sú
        $this->assertFalse($m->benchmark('us_long')['short_history']);
        $this->assertGreaterThan(25, $m->benchmark('us_long')['years']);

        // metadáta v konštante musia zodpovedať poradiu od najdlhšieho radu
        $since = array_column(MarketDataService::BENCHMARKS, 'since');
        $sorted = $since;
        sort($sorted);
        $this->assertSame($sorted, $since);
    }

    public function test_inflation_history_comes_from_ecb(): void
    {
        $i = app(MarketDataService::class)->inflation('SK');

        $this->assertNotNull($i);
        $this->assertSame(3.0, $i['avg']);
        $this->assertSame(3.0, $i['avg20']);
    }

    public function test_projection_produces_ordered_percentile_bands(): void
    {
        $r = app(RetirementService::class)->project($this->user, 10_000, [
            'year' => 2065, 'monthly' => 300, 'inflation' => 3.0, 'engine' => 'world',
        ]);

        $this->assertTrue($r['ok']);
        $this->assertCount($r['years'] + 1, $r['series']);
        $this->assertSame(2065, end($r['series'])['year']);

        foreach ($r['series'] as $point) {
            $this->assertLessThanOrEqual($point['p25'], $point['p10']);
            $this->assertLessThanOrEqual($point['p50'], $point['p25']);
            $this->assertLessThanOrEqual($point['p75'], $point['p50']);
            $this->assertLessThanOrEqual($point['p90'], $point['p75']);
        }

        // prvý bod je dnešok — všetky scenáre štartujú na rovnakej hodnote
        $this->assertSame(10_000.0, $r['series'][0]['p50']);

        // dnešné eurá musia byť nižšie než nominálne (inflácia > 0)
        $this->assertLessThan($r['final']['nominal']['p50'], $r['final']['real']['p50']);
    }

    public function test_contributions_are_indexed_to_inflation(): void
    {
        $svc = app(RetirementService::class);

        $flat = $svc->project($this->user, 0, ['year' => 2046, 'monthly' => 100, 'inflation' => 3.0, 'index_contributions' => false]);
        $indexed = $svc->project($this->user, 0, ['year' => 2046, 'monthly' => 100, 'inflation' => 3.0, 'index_contributions' => true]);

        $this->assertGreaterThan($flat['final']['contributed'], $indexed['final']['contributed']);
    }

    public function test_required_monthly_contribution_reaches_the_target(): void
    {
        $r = app(RetirementService::class)->project($this->user, 0, [
            'year' => 2060, 'monthly' => 50, 'target_income' => 1_500, 'withdrawal' => 4.0,
        ]);

        $required = $r['target']['required_monthly'];
        $this->assertNotNull($required);

        // pri odporúčanom vklade musí medián naozaj dosiahnuť cieľovú rentu
        $check = app(RetirementService::class)->project($this->user, 0, [
            'year' => 2060, 'monthly' => $required, 'target_income' => 1_500, 'withdrawal' => 4.0,
        ]);
        $this->assertGreaterThanOrEqual(1_500 * 0.99, $check['final']['income']['p50']);

        // opatrný plán (90 % scenárov) musí byť drahší než mediánový
        $this->assertGreaterThan($required, $r['target']['required_monthly_safe']);
    }

    public function test_a_year_in_the_past_is_clamped_to_the_nearest_usable_one(): void
    {
        $r = app(RetirementService::class)->project($this->user, 1_000, ['year' => 1990]);

        $this->assertTrue($r['ok']);
        $this->assertSame(CarbonImmutable::today()->year + 1, $r['params']['year']);
        $this->assertSame(1, $r['years']);
    }

    public function test_freedom_year_is_the_first_year_the_median_covers_the_fire_number(): void
    {
        $r = app(RetirementService::class)->project($this->user, 20_000, [
            'year' => 2065, 'monthly' => 500, 'spending' => 1_200, 'withdrawal' => 4.0, 'inflation' => 3.0,
        ]);

        $f = $r['freedom'];
        $this->assertNotNull($f);
        $this->assertSame(1_200 * 12 / 0.04, $f['fire_number']);
        $this->assertTrue($f['reached']);

        // v roku slobody už medián pokrýva potrebnú sumu, o rok skôr ešte nie
        $byYear = collect($r['series'])->keyBy('year');
        $this->assertGreaterThanOrEqual($f['fire_number'], $byYear[$f['year']]['real_p50']);
        $this->assertLessThan($f['fire_number'], $byYear[$f['year'] - 1]['real_p50']);

        // opatrný scenár nemôže prísť skôr než stredný, šťastný nie neskôr
        $this->assertGreaterThanOrEqual($f['year'], $f['year_safe']);
        $this->assertLessThanOrEqual($f['year'], $f['year_lucky']);
    }

    public function test_freedom_beyond_the_target_year_still_reports_a_year(): void
    {
        // cieľový rok zámerne skorý, aby sa doň sloboda nestihla
        $r = app(RetirementService::class)->project($this->user, 1_000, [
            'year' => 2035, 'monthly' => 200, 'spending' => 500, 'withdrawal' => 4.0, 'inflation' => 3.0,
        ]);

        $f = $r['freedom'];
        $this->assertTrue($f['reached']);
        $this->assertFalse($f['within_plan']);
        // rok existuje a leží za plánovaným odchodom
        $this->assertGreaterThan(2035, $f['year']);
        $this->assertLessThanOrEqual($f['horizon_year'], $f['year']);
        $this->assertLessThan(0, $f['years_earlier']);
    }

    public function test_a_missed_target_year_comes_with_the_contribution_that_would_hit_it(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2035, 'spending' => 500, 'withdrawal' => 4.0, 'inflation' => 3.0];

        $r = $svc->project($this->user, 1_000, $args + ['monthly' => 200]);
        $required = $r['freedom']['required_monthly'];

        $this->assertNotNull($required);
        $this->assertGreaterThan(200, $required);
        $this->assertSame(round($required - 200, 2), $r['freedom']['required_extra']);

        // pri odporúčanom vklade to už do cieľového roku vyjde
        $fixed = $svc->project($this->user, 1_000, $args + ['monthly' => $required]);
        $this->assertTrue($fixed['freedom']['within_plan']);
        $this->assertLessThanOrEqual(2035, $fixed['freedom']['year']);
    }

    public function test_a_reachable_plan_needs_no_extra_contribution(): void
    {
        $r = app(RetirementService::class)->project($this->user, 200_000, [
            'year' => 2065, 'monthly' => 1_000, 'spending' => 800, 'withdrawal' => 4.0, 'inflation' => 3.0,
        ]);

        $this->assertTrue($r['freedom']['within_plan']);
        $this->assertNull($r['freedom']['required_monthly']);
        $this->assertGreaterThan(0, $r['freedom']['years_earlier']);
    }

    // ── Výberová fáza ───────────────────────────────────────────────────

    public function test_a_longer_retirement_is_harder_to_survive(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2045, 'monthly' => 500, 'spending' => 1_000, 'withdrawal' => 4.0, 'inflation' => 3.0];

        $short = $svc->project($this->user, 300_000, $args + ['duration' => 20]);
        $long = $svc->project($this->user, 300_000, $args + ['duration' => 45]);

        $this->assertSame(20, $short['withdrawal']['duration']);

        // porovnáva sa tabuľka sadzieb — tam obe štartujú z presne nasporenej
        // sumy, takže sa líši výlučne dĺžka dôchodku
        $shortAt4 = $short['withdrawal']['rates'][0]['success_pct'];
        $longAt4 = $long['withdrawal']['rates'][0]['success_pct'];
        $this->assertSame(4.0, $short['withdrawal']['rates'][0]['rate']);
        $this->assertGreaterThan($longAt4, $shortAt4);
    }

    public function test_a_lower_withdrawal_rate_always_survives_better(): void
    {
        $r = app(RetirementService::class)->project($this->user, 200_000, [
            'year' => 2045, 'monthly' => 300, 'spending' => 1_200, 'duration' => 35, 'inflation' => 3.0,
        ]);

        $rates = $r['withdrawal']['rates'];
        $this->assertCount(6, $rates);

        // nižšia miera výberu vyžaduje viac peňazí a musí prežiť častejšie
        $previous = null;
        foreach ($rates as $row) {
            if ($previous !== null) {
                $this->assertLessThan($previous['rate'], $row['rate']);
                $this->assertGreaterThan($previous['needed'], $row['needed']);
                $this->assertGreaterThanOrEqual($previous['success_pct'], $row['success_pct']);
            }
            $previous = $row;
        }
    }

    public function test_the_safe_rate_is_the_first_one_reaching_the_target(): void
    {
        $w = app(RetirementService::class)->project($this->user, 200_000, [
            'year' => 2045, 'monthly' => 300, 'spending' => 1_200, 'duration' => 35, 'inflation' => 3.0,
        ])['withdrawal'];

        if ($w['safe_rate'] === null) {
            $this->assertLessThan($w['target'], max(array_column($w['rates'], 'success_pct')));

            return;
        }

        $this->assertGreaterThanOrEqual($w['target'], $w['safe_rate']['success_pct']);

        // všetky vyššie miery výberu musia cieľ minúť — inak by bezpečná bola iná
        foreach ($w['rates'] as $row) {
            if ($row['rate'] > $w['safe_rate']['rate']) {
                $this->assertLessThan($w['target'], $row['success_pct']);
            }
        }
    }

    public function test_a_depleted_path_reports_when_the_money_ran_out(): void
    {
        // zámerne neudržateľné: malý štart, veľké výdavky
        $w = app(RetirementService::class)->project($this->user, 10_000, [
            'year' => 2030, 'monthly' => 0, 'spending' => 3_000, 'duration' => 40, 'withdrawal' => 4.0,
        ])['withdrawal'];

        $this->assertLessThan(50, $w['success_pct']);
        $this->assertNotNull($w['depleted_year']);
        $this->assertGreaterThanOrEqual(2030, $w['depleted_year']);
    }

    public function test_the_withdrawal_phase_needs_a_spending_figure(): void
    {
        $r = app(RetirementService::class)->project($this->user, 100_000, ['year' => 2045, 'monthly' => 200]);

        $this->assertNull($r['withdrawal']);
    }

    public function test_the_retirement_duration_is_saved(): void
    {
        $this->actingAs($this->user)->post('/retirement', [
            'year' => 2065, 'duration' => 42, 'monthly' => 300, 'index_contributions' => true,
            'inflation' => 3.1, 'fees' => 0.25, 'haircut' => 2.5, 'withdrawal' => 3.5,
            'engine' => 'us_long', 'spending' => 1_400,
        ])->assertRedirect();

        $this->assertSame(42, $this->user->fresh()->retire_duration);
    }

    public function test_freedom_is_absent_without_a_spending_figure(): void
    {
        $r = app(RetirementService::class)->project($this->user, 20_000, ['year' => 2065, 'monthly' => 500]);

        $this->assertNull($r['freedom']);
    }

    public function test_coasting_from_the_coast_year_still_reaches_the_target(): void
    {
        $r = app(RetirementService::class)->project($this->user, 20_000, [
            'year' => 2065, 'monthly' => 500, 'spending' => 1_200, 'withdrawal' => 4.0, 'inflation' => 3.0,
        ]);

        $coast = $r['freedom']['coast_year'];
        $this->assertNotNull($coast);

        // Coast FIRE nemôže nastať neskôr než samotná sloboda — dovtedy sa ešte vkladá
        $this->assertLessThanOrEqual($r['freedom']['year'], $coast);
        $this->assertGreaterThanOrEqual(CarbonImmutable::today()->year, $coast);
    }

    public function test_a_bigger_contribution_brings_freedom_closer(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2065, 'spending' => 800, 'withdrawal' => 4.0, 'inflation' => 3.0];

        $small = $svc->project($this->user, 20_000, $args + ['monthly' => 300]);
        $big = $svc->project($this->user, 20_000, $args + ['monthly' => 1_200]);

        $this->assertLessThan($small['freedom']['year'], $big['freedom']['year']);
    }

    public function test_measured_profile_reads_the_savings_rate_from_transactions(): void
    {
        $account = Account::create([
            'user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 5_000, 'color' => '#4c8dff',
        ]);

        // tri ukončené mesiace: príjem 2 000, výdavky 1 500 → miera úspor 25 %
        for ($i = 1; $i <= 3; $i++) {
            $month = CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(4);
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $account->id, 'type' => 'income',
                'amount' => 2_000, 'date' => $month->toDateString(),
            ]);
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $account->id, 'type' => 'expense',
                'amount' => 1_500, 'date' => $month->toDateString(),
            ]);
        }

        $profile = app(FinancialProfileService::class)->forUser($this->user->fresh());

        $this->assertTrue($profile['measured']['has_data']);
        $this->assertSame(2_000.0, $profile['measured']['income']);
        $this->assertSame(1_500.0, $profile['measured']['expense']);
        $this->assertSame(500.0, $profile['measured']['savings']);
        $this->assertSame(25.0, $profile['measured']['savings_rate']);
    }

    public function test_years_to_freedom_follows_the_classic_savings_rate_maths(): void
    {
        $svc = app(FinancialProfileService::class);

        // klasická tabuľka: 50 % úspor ≈ 17 rokov, 10 % ≈ 51 rokov
        $this->assertEqualsWithDelta(16.6, $svc->yearsToFreedom(50), 0.3);
        $this->assertEqualsWithDelta(51.4, $svc->yearsToFreedom(10), 0.3);
        $this->assertNull($svc->yearsToFreedom(0));
    }

    public function test_xirr_matches_a_known_cashflow(): void
    {
        $xirr = app(PortfolioAnalyticsService::class)->xirr([
            ['date' => CarbonImmutable::create(2020, 1, 1), 'amount' => -1_000],
            ['date' => CarbonImmutable::create(2021, 1, 1), 'amount' => 1_100],
        ]);

        $this->assertEqualsWithDelta(0.10, $xirr, 0.002);
    }

    public function test_analytics_reports_xirr_and_concentration(): void
    {
        $inv = Investment::create([
            'user_id' => $this->user->id, 'ticker' => 'VWCE', 'name' => 'Vanguard All-World',
            'kind' => 'etf', 'quote_source' => 'manual', 'current_price' => 120, 'color' => '#4c8dff',
        ]);
        $inv->lots()->create(['type' => 'buy', 'units' => 10, 'price' => 100, 'date' => CarbonImmutable::today()->subYears(2)->toDateString()]);
        $inv->recomputeFromLots();

        $a = app(PortfolioAnalyticsService::class)->forUser($this->user->fresh());

        $this->assertTrue($a['ok']);
        $this->assertSame(1_200.0, $a['value']);
        $this->assertSame(1_000.0, $a['invested']);
        // 1 000 € → 1 200 € za dva roky ≈ 9,5 % p.a.
        $this->assertEqualsWithDelta(9.5, $a['xirr'], 0.5);
        $this->assertSame(100.0, $a['allocation']['top_weight']);
        $this->assertSame(1.0, $a['allocation']['effective_positions']);
    }

    public function test_scenarios_show_what_a_higher_contribution_would_do(): void
    {
        $r = app(RetirementService::class)->project($this->user, 10_000, [
            'year' => 2065, 'monthly' => 100, 'spending' => 1_000, 'withdrawal' => 4.0, 'inflation' => 3.0,
        ]);

        $s = $r['scenarios'];
        $this->assertSame(100.0, $s['base']['monthly']);
        $this->assertCount(4, $s['ladder']);
        $this->assertNull($s['custom']);

        // každý ďalší stupienok musí dať viac a slobodu skôr
        $previous = $s['base'];
        foreach ($s['ladder'] as $step) {
            $this->assertGreaterThan($previous['monthly'], $step['monthly']);
            $this->assertGreaterThan($previous['real_p50'], $step['real_p50']);
            $this->assertGreaterThan($previous['income_p50'], $step['income_p50']);
            // null = pri tomto tempe sa sloboda v horizonte nedosiahne;
            // vyšší vklad ju musí buď priniesť, alebo posunúť dopredu
            if ($previous['freedom_year'] !== null) {
                $this->assertNotNull($step['freedom_year']);
                $this->assertLessThanOrEqual($previous['freedom_year'], $step['freedom_year']);
            }
            $previous = $step;
        }
    }

    public function test_a_custom_comparison_amount_is_returned(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2065, 'monthly' => 100, 'spending' => 1_000, 'withdrawal' => 4.0, 'inflation' => 3.0];

        $r = $svc->project($this->user, 10_000, $args + ['compare' => 250]);

        $this->assertSame(250.0, $r['scenarios']['custom']['monthly']);
        $this->assertSame(150.0, $r['scenarios']['custom']['extra']);

        // vlastný scenár musí sedieť s tým, čo by dal plán s rovnakým vkladom
        $direct = $svc->project($this->user, 10_000, ['year' => 2065, 'monthly' => 250, 'spending' => 1_000, 'withdrawal' => 4.0, 'inflation' => 3.0]);
        $this->assertSame($direct['scenarios']['base']['real_p50'], $r['scenarios']['custom']['real_p50']);
        $this->assertSame($direct['freedom']['year'], $r['scenarios']['custom']['freedom_year']);
    }

    public function test_scenarios_omit_the_freedom_year_without_a_spending_figure(): void
    {
        $r = app(RetirementService::class)->project($this->user, 10_000, ['year' => 2065, 'monthly' => 100]);

        $this->assertNull($r['scenarios']['fire_number']);
        $this->assertNull($r['scenarios']['base']['freedom_year']);
        $this->assertGreaterThan(0, $r['scenarios']['base']['real_p50']);
    }

    public function test_the_comparison_does_not_change_the_saved_plan(): void
    {
        $this->user->update(['retire_monthly' => 100]);

        $this->actingAs($this->user)
            ->getJson('/retirement/simulate?year=2065&monthly=100&spending=1000&compare=900')
            ->assertOk()
            ->assertJsonPath('scenarios.custom.monthly', 900)
            ->assertJsonPath('params.monthly', 100);

        $this->assertSame('100.00', $this->user->fresh()->retire_monthly);
    }

    // ── Oplatí sa mi to? ────────────────────────────────────────────────

    public function test_a_purchase_shows_what_the_money_would_have_grown_into(): void
    {
        $r = app(RetirementService::class)->project($this->user, 20_000, [
            'year' => 2065, 'monthly' => 200, 'spending' => 1_200, 'spend' => 1_000,
        ]);

        $p = $r['purchase'];
        $this->assertSame(1_000.0, $p['amount']);
        $this->assertFalse($p['recurring']);

        // hodnota rastie s horizontom a nikdy nie je nižšia než samotná suma
        $previous = 0;
        foreach ($p['horizons'] as $h) {
            $this->assertGreaterThan($previous, $h['value']);
            $previous = $h['value'];
        }
        $this->assertGreaterThan(1_000, $p['horizons'][0]['value']);
    }

    public function test_the_purchase_result_scales_exactly_with_the_amount(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2065, 'monthly' => 200, 'spending' => 1_200];

        $small = $svc->project($this->user, 20_000, $args + ['spend' => 100]);
        $big = $svc->project($this->user, 20_000, $args + ['spend' => 2_500]);

        // to isté euro musí vyjsť rovnako bez ohľadu na cenu nákupu
        foreach ($small['purchase']['horizons'] as $i => $h) {
            $this->assertEqualsWithDelta(
                $h['value'] / 100,
                $big['purchase']['horizons'][$i]['value'] / 2_500,
                0.001,
                "horizont {$h['years']} rokov nescaluje lineárne"
            );
        }
    }

    public function test_the_purchase_value_is_expressed_in_todays_euros(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2065, 'monthly' => 200, 'spending' => 1_200, 'spend' => 1_000];

        $noInflation = $svc->project($this->user, 20_000, $args + ['inflation' => 0]);
        $withInflation = $svc->project($this->user, 20_000, $args + ['inflation' => 5]);

        // pri inflácii musí byť tá istá suma v dnešných eurách výrazne nižšia
        $this->assertLessThan(
            end($noInflation['purchase']['horizons'])['value'] * 0.5,
            end($withInflation['purchase']['horizons'])['value']
        );
    }

    public function test_a_recurring_cost_hurts_far_more_than_the_same_amount_once(): void
    {
        $svc = app(RetirementService::class);
        $args = ['year' => 2065, 'monthly' => 200, 'spending' => 1_200];

        $once = $svc->project($this->user, 20_000, $args + ['spend' => 50]);
        $monthly = $svc->project($this->user, 20_000, $args + ['spend' => 50, 'spend_monthly' => true]);

        $lastOnce = end($once['purchase']['horizons'])['value'];
        $lastMonthly = end($monthly['purchase']['horizons'])['value'];

        $this->assertTrue($monthly['purchase']['recurring']);
        $this->assertGreaterThan($lastOnce * 5, $lastMonthly);
    }

    public function test_a_purchase_can_push_the_freedom_year_back(): void
    {
        // veľký opakovaný výdavok musí slobodu odsunúť
        $r = app(RetirementService::class)->project($this->user, 20_000, [
            'year' => 2065, 'monthly' => 500, 'spending' => 1_200, 'spend' => 300, 'spend_monthly' => true,
        ]);

        $p = $r['purchase'];
        $this->assertNotNull($p['freedom_if_saved']);
        $this->assertGreaterThan($p['freedom_if_saved'], $p['freedom_if_spent']);
        $this->assertGreaterThan(0, $p['delay_years']);
    }

    public function test_without_an_amount_there_is_no_purchase_block(): void
    {
        $r = app(RetirementService::class)->project($this->user, 20_000, ['year' => 2065, 'monthly' => 200]);

        $this->assertNull($r['purchase']);
    }

    public function test_the_purchase_page_and_endpoint_respond_without_touching_the_plan(): void
    {
        $this->user->update(['retire_monthly' => 250, 'retire_spending' => 1_100]);

        $this->actingAs($this->user)->get('/purchase')->assertOk();

        $this->actingAs($this->user)
            ->getJson('/purchase/calculate?amount=800')
            ->assertOk()
            ->assertJsonPath('purchase.amount', 800)
            ->assertJsonStructure(['purchase' => ['horizons', 'freedom_if_saved', 'freedom_if_spent'], 'context']);

        $this->user->refresh();
        $this->assertSame('250.00', $this->user->retire_monthly);
        $this->assertSame('1100.00', $this->user->retire_spending);
    }

    public function test_the_monthly_contribution_is_measured_from_actual_purchases(): void
    {
        $inv = $this->investmentWithMonthlyBuys(100.0, 12);
        // dva jednorazové vklady, ktoré by priemer vytiahli na násobok
        $inv->lots()->create(['type' => 'buy', 'units' => 25, 'price' => 100, 'date' => CarbonImmutable::today()->subMonthsNoOverflow(4)->startOfMonth()->addDay()->toDateString()]);
        $inv->lots()->create(['type' => 'buy', 'units' => 30, 'price' => 100, 'date' => CarbonImmutable::today()->subMonthsNoOverflow(3)->startOfMonth()->addDay()->toDateString()]);

        $c = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12);

        $this->assertTrue($c['has_data']);
        // medián drží tempo 100 €, priemer je kvôli dvom mesiacom násobne vyšší
        $this->assertSame(100.0, $c['median']);
        $this->assertSame(100.0, $c['recommended']);
        $this->assertGreaterThan(400, $c['mean']);
        $this->assertCount(2, $c['lumps']);
        // do jednorazového mesiaca spadne aj pravidelný nákup toho mesiaca
        $this->assertSame(5_700.0, $c['lump_total']);
        $this->assertSame('median', $c['basis']);
    }

    public function test_months_without_a_purchase_count_into_the_pace(): void
    {
        // nakupuje len každý tretí mesiac — mesiace bez nákupu sú v tempe tiež
        $this->investmentWithMonthlyBuys(200.0, 12, step: 3);

        $c = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12);

        // väčšina mesiacov je nulová, takže medián padne na nulu…
        $this->assertSame(0.0, $c['median']);
        // …a namiesto neho sa použije očistený priemer, nie nula
        $this->assertSame('trimmed_mean', $c['basis']);
        $this->assertGreaterThan(0, $c['recommended']);
        $this->assertEqualsWithDelta(80.0, $c['recommended'], 20);
    }

    public function test_sales_reduce_the_measured_contribution(): void
    {
        $inv = $this->investmentWithMonthlyBuys(100.0, 12);
        $inv->lots()->create(['type' => 'sell', 'units' => 1, 'price' => 100, 'date' => CarbonImmutable::today()->subMonthsNoOverflow(2)->startOfMonth()->addDay()->toDateString()]);

        $c = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12);
        $month = collect($c['series'])->firstWhere('ym', CarbonImmutable::today()->subMonthsNoOverflow(2)->format('Y-m'));

        $this->assertSame(0.0, $month['amount']); // 100 € nákup − 100 € predaj
    }

    public function test_a_position_without_recent_purchases_is_flagged(): void
    {
        // do VWCE sa prispieva, do BTC už rok nie — hoci sa doň možno kupuje ďalej
        $this->investmentWithMonthlyBuys(100.0, 12);
        $btc = Investment::create([
            'user_id' => $this->user->id, 'ticker' => 'BTC', 'name' => 'Bitcoin',
            'kind' => 'crypto', 'quote_source' => 'manual', 'current_price' => 50_000, 'color' => '#f7931a',
        ]);
        $btc->lots()->create(['type' => 'buy', 'units' => 0.1, 'price' => 40_000, 'date' => CarbonImmutable::today()->subMonths(10)->toDateString()]);
        $btc->recomputeFromLots();

        $c = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12);
        $stale = $c['reconciliation']['stale'];

        $this->assertCount(1, $stale);
        $this->assertSame('BTC', $stale[0]['ticker']);
        $this->assertGreaterThanOrEqual(9, $stale[0]['months_since']);
    }

    public function test_a_position_marked_as_finished_stops_being_flagged(): void
    {
        $this->investmentWithMonthlyBuys(100.0, 12);
        $btc = Investment::create([
            'user_id' => $this->user->id, 'ticker' => 'BTC', 'name' => 'Bitcoin',
            'kind' => 'crypto', 'quote_source' => 'manual', 'current_price' => 50_000, 'color' => '#f7931a',
        ]);
        $btc->lots()->create(['type' => 'buy', 'units' => 0.1, 'price' => 40_000, 'date' => CarbonImmutable::today()->subMonths(10)->toDateString()]);
        $btc->recomputeFromLots();

        $svc = app(PortfolioAnalyticsService::class);
        $this->assertCount(1, $svc->investmentContributions($this->user->fresh(), 12)['reconciliation']['stale']);

        $this->actingAs($this->user)
            ->patch("/investments/{$btc->id}/contributing", ['contributing' => false])
            ->assertRedirect();

        $this->assertFalse($btc->fresh()->contributing);
        $this->assertSame([], $svc->investmentContributions($this->user->fresh(), 12)['reconciliation']['stale']);
    }

    public function test_another_users_investment_cannot_be_marked(): void
    {
        $stranger = User::factory()->create();
        $foreign = Investment::create([
            'user_id' => $stranger->id, 'ticker' => 'XXX', 'name' => 'Cudzie',
            'kind' => 'etf', 'quote_source' => 'manual', 'current_price' => 1, 'color' => '#000000',
        ]);

        $this->actingAs($this->user)
            ->patch("/investments/{$foreign->id}/contributing", ['contributing' => false])
            ->assertForbidden();

        $this->assertTrue($foreign->fresh()->contributing);
    }

    public function test_a_freshly_bought_position_is_not_flagged(): void
    {
        $this->investmentWithMonthlyBuys(100.0, 12);

        $c = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12);

        $this->assertSame([], $c['reconciliation']['stale']);
    }

    public function test_money_booked_to_investments_is_compared_with_recorded_purchases(): void
    {
        $this->investmentWithMonthlyBuys(100.0, 12);

        // do kategórie Investície je zaúčtované trojnásobne viac, než je zapísané
        $account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 0, 'color' => '#4c8dff']);
        $group = Category::create(['user_id' => $this->user->id, 'name' => 'Investície', 'type' => 'expense', 'is_savings' => true, 'color' => '#4c8dff', 'icon' => '📈', 'position' => 1]);
        for ($i = 1; $i <= 12; $i++) {
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $account->id, 'category_id' => $group->id,
                'type' => 'expense', 'amount' => 300,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDay()->toDateString(),
            ]);
        }

        $r = app(PortfolioAnalyticsService::class)->investmentContributions($this->user->fresh(), 12)['reconciliation'];

        $this->assertEqualsWithDelta(1_200, $r['recorded'], 200);
        $this->assertEqualsWithDelta(3_600, $r['booked'], 400);
        $this->assertTrue($r['mismatch']);
    }

    public function test_the_plan_prefills_the_measured_contribution(): void
    {
        $this->investmentWithMonthlyBuys(150.0, 12);

        $this->actingAs($this->user)->get('/retirement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('plan.monthly', fn ($v) => abs((float) $v - 150) < 0.01)
                ->where('contributions.recommended', fn ($v) => abs((float) $v - 150) < 0.01)
            );
    }

    public function test_a_saved_plan_beats_the_measured_contribution(): void
    {
        $this->investmentWithMonthlyBuys(150.0, 12);
        $this->user->update(['retire_monthly' => 500]);

        $this->actingAs($this->user)->get('/retirement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('plan.monthly', fn ($v) => abs((float) $v - 500) < 0.01));
    }

    /** Investícia s pravidelným nákupom za danú sumu v posledných N mesiacoch. */
    protected function investmentWithMonthlyBuys(float $amount, int $months, int $step = 1): Investment
    {
        $inv = Investment::create([
            'user_id' => $this->user->id, 'ticker' => 'VWCE', 'name' => 'All-World',
            'kind' => 'etf', 'quote_source' => 'manual', 'current_price' => 100, 'color' => '#4c8dff',
        ]);

        for ($i = 1; $i <= $months; $i += $step) {
            $inv->lots()->create([
                'type' => 'buy',
                'units' => $amount / 100,
                'price' => 100,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDay()->toDateString(),
            ]);
        }
        $inv->recomputeFromLots();

        return $inv;
    }

    public function test_retirement_page_and_simulation_endpoint_respond(): void
    {
        $this->actingAs($this->user)->get('/retirement')->assertOk();

        $this->actingAs($this->user)
            ->getJson('/retirement/simulate?year=2065&monthly=300&target_income=1500')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['series', 'final', 'target', 'engine', 'inflation']);
    }

    public function test_plan_is_saved_to_the_profile(): void
    {
        $this->actingAs($this->user)->post('/retirement', [
            'year' => 2065, 'duration' => 35, 'monthly' => 420, 'index_contributions' => true,
            'inflation' => 3.2, 'fees' => 0.2, 'haircut' => 1.5, 'withdrawal' => 3.5,
            'engine' => 'sp500tr', 'target_income' => 2000,
        ])->assertRedirect();

        $this->user->refresh();
        $this->assertSame(2065, $this->user->retire_year);
        $this->assertSame('420.00', $this->user->retire_monthly);
        $this->assertSame('sp500tr', $this->user->retire_engine);
    }
}
