<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialProfileService;
use App\Services\PortfolioAnalyticsService;
use App\Services\RetirementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dôchodková projekcia v troch scenároch výnosu, meranie vkladov a stránka
 * „Oplatí sa?", ktorá stojí na tých istých predpokladoch.
 */
class RetirementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // porovnanie s benchmarkom na stránke investícií nesmie siahať na sieť
        Http::fake(['*' => Http::response([], 404)]);
    }

    protected function project(float $start, array $overrides): array
    {
        return app(RetirementService::class)->project($this->user, $start, $overrides);
    }

    // ── Projekcia ───────────────────────────────────────────────────────

    public function test_the_three_scenarios_are_ordered_and_labelled(): void
    {
        $r = $this->project(10_000, ['year' => CarbonImmutable::today()->year + 30, 'monthly' => 300]);

        $this->assertTrue($r['ok']);
        $byKey = collect($r['scenarios'])->keyBy('key');
        $this->assertSame([3.0, 5.0, 7.0], [$byKey['low']['real_return'], $byKey['mid']['real_return'], $byKey['high']['real_return']]);
        $this->assertLessThan($byKey['mid']['final'], $byKey['low']['final']);
        $this->assertLessThan($byKey['high']['final'], $byKey['mid']['final']);

        foreach ($r['series'] as $row) {
            $this->assertLessThanOrEqual($row['mid'], $row['low']);
            $this->assertLessThanOrEqual($row['high'], $row['mid']);
        }
        $this->assertCount(31, $r['series']);
    }

    public function test_the_value_matches_the_annuity_formula(): void
    {
        $svc = app(RetirementService::class);

        // bez výnosu je to len súčet vkladov
        $this->assertSame(1_000.0 + 100 * 120, $svc->valueAt(1_000, 100, 0.0, 10));
        // 1 000 € pri 5 % na 10 rokov = 1 000 × 1,05^10
        $this->assertEqualsWithDelta(1_628.89, $svc->valueAt(1_000, 0, 5.0, 10), 0.01);
        // 100 € mesačne, 10 rokov, 5 % ročne (vklad na začiatku mesiaca)
        $this->assertEqualsWithDelta(15_499.2, $svc->valueAt(0, 100, 5.0, 10), 0.5);
    }

    public function test_the_income_follows_the_withdrawal_rate(): void
    {
        $r = $this->project(0, ['year' => CarbonImmutable::today()->year + 20, 'monthly' => 500, 'withdrawal' => 4]);
        $mid = collect($r['scenarios'])->firstWhere('key', 'mid');

        $this->assertEqualsWithDelta($mid['final'] * 0.04 / 12, $mid['income'], 0.01);
    }

    public function test_the_fire_number_is_annual_spending_over_the_withdrawal_rate(): void
    {
        $svc = app(RetirementService::class);

        // 1 500 × 12 / 4 % — so štátnym dôchodkom sa zámerne nepočíta
        $this->assertSame(450_000.0, $svc->fireNumber(['spending' => 1_500.0, 'withdrawal' => 4.0]));
        $this->assertNull($svc->fireNumber(['spending' => null, 'withdrawal' => 4.0]));
    }

    public function test_freedom_is_a_range_from_the_good_to_the_cautious_scenario(): void
    {
        $r = $this->project(50_000, ['year' => CarbonImmutable::today()->year + 30, 'monthly' => 800, 'spending' => 1_500]);
        $f = $r['freedom'];

        $this->assertNotNull($f['year']);
        $this->assertLessThanOrEqual($f['year'], $f['earliest']);
        $this->assertGreaterThanOrEqual($f['year'], $f['latest']);
    }

    public function test_a_missed_target_year_comes_with_the_contribution_that_would_hit_it(): void
    {
        $year = CarbonImmutable::today()->year + 20;
        $r = $this->project(0, ['year' => $year, 'monthly' => 100, 'spending' => 2_000]);
        $f = $r['freedom'];

        $this->assertFalse($f['within_plan']);
        $this->assertGreaterThan(100, $f['required_monthly']);

        // s tým vkladom stredný scenár naozaj dosiahne cieľ práve v pláne
        $hit = $this->project(0, ['year' => $year, 'monthly' => $f['required_monthly'], 'spending' => 2_000]);
        $this->assertTrue($hit['freedom']['within_plan']);
        $this->assertNull($hit['freedom']['required_monthly']);
    }

    public function test_freedom_is_absent_without_a_spending_figure(): void
    {
        $r = $this->project(10_000, ['year' => CarbonImmutable::today()->year + 30, 'monthly' => 300]);

        $this->assertNull($r['freedom']);
        $this->assertNull($r['scenarios'][0]['freedom_year']);
    }

    public function test_a_year_in_the_past_is_clamped_to_the_nearest_usable_one(): void
    {
        $p = app(RetirementService::class)->resolveParams($this->user, ['year' => 1990]);

        $this->assertSame(CarbonImmutable::today()->year + 1, $p['year']);
    }

    public function test_the_expected_nominal_return_adds_inflation_to_the_real_one(): void
    {
        // (1,05 × 1,02) − 1 = 7,1 %
        $this->assertSame(7.1, app(RetirementService::class)->expectedNominalReturn());
    }

    // ── Namerané z transakcií ───────────────────────────────────────────

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

    public function test_xirr_is_not_annualised_before_a_full_year(): void
    {
        $inv = Investment::create([
            'user_id' => $this->user->id, 'ticker' => 'VWCE', 'name' => 'Vanguard All-World',
            'kind' => 'etf', 'quote_source' => 'manual', 'current_price' => 105, 'color' => '#4c8dff',
        ]);
        $inv->lots()->create(['type' => 'buy', 'units' => 10, 'price' => 100, 'date' => CarbonImmutable::today()->subMonths(3)->toDateString()]);
        $inv->recomputeFromLots();

        $a = app(PortfolioAnalyticsService::class)->forUser($this->user->fresh());

        // +5 % za tri mesiace by sa ukázalo ako ~+21 % ročne
        $this->assertFalse($a['annualized']);
        $this->assertNull($a['xirr']);
        $this->assertSame(5.0, $a['simple_pct']);
    }

    // ── Oplatí sa? ──────────────────────────────────────────────────────

    public function test_a_purchase_shows_its_value_in_three_scenarios(): void
    {
        $this->user->update(['retire_year' => CarbonImmutable::today()->year + 30]);

        $r = $this->actingAs($this->user)->getJson('/purchase/calculate?amount=1000')->assertOk()->json();
        $last = end($r['purchase']['horizons']);

        $this->assertSame(30, $last['years']);
        // 1 000 € × 1,05^30 ≈ 4 322 €
        $this->assertEqualsWithDelta(4_321.94, $last['mid'], 0.5);
        $this->assertLessThan($last['mid'], $last['low']);
        $this->assertGreaterThan($last['mid'], $last['high']);
    }

    public function test_a_recurring_cost_hurts_far_more_than_the_same_amount_once(): void
    {
        $this->user->update(['retire_year' => CarbonImmutable::today()->year + 30]);

        $once = $this->actingAs($this->user)->getJson('/purchase/calculate?amount=50')->json('purchase.horizons');
        $monthly = $this->actingAs($this->user)->getJson('/purchase/calculate?amount=50&recurring=1')->json('purchase.horizons');

        $this->assertGreaterThan(end($once)['mid'] * 50, end($monthly)['mid']);
    }

    public function test_the_purchase_page_and_endpoint_respond_without_touching_the_plan(): void
    {
        $this->user->update(['retire_monthly' => 250, 'retire_spending' => 1_100]);

        $this->actingAs($this->user)->get('/purchase')->assertOk();

        $this->actingAs($this->user)
            ->getJson('/purchase/calculate?amount=800')
            ->assertOk()
            ->assertJsonPath('purchase.amount', 800)
            ->assertJsonStructure(['purchase' => ['horizons' => [['years', 'year', 'low', 'mid', 'high']]], 'context']);

        $this->user->refresh();
        $this->assertSame('250.00', $this->user->retire_monthly);
        $this->assertSame('1100.00', $this->user->retire_spending);
    }

    // ── Merané tempo vkladov ────────────────────────────────────────────

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
            ->getJson('/retirement/simulate?year=2065&monthly=300&spending=1500')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('freedom.monthly_spending', 1500)
            ->assertJsonStructure(['series', 'scenarios', 'freedom', 'contributed']);
    }

    public function test_plan_is_saved_to_the_profile(): void
    {
        $this->actingAs($this->user)->post('/retirement', [
            'year' => 2065, 'monthly' => 420, 'withdrawal' => 3.5, 'spending' => 1_400, 'housing' => 700,
        ])->assertRedirect();

        $this->user->refresh();
        $this->assertSame(2065, $this->user->retire_year);
        $this->assertSame('420.00', $this->user->retire_monthly);
        $this->assertSame('3.50', $this->user->retire_withdrawal);
        $this->assertSame('700.00', $this->user->retire_housing);
    }

    // ── Výdavky v dôchodku a bývanie ────────────────────────────────────

    /** Študent: 2 mesiace spotreby po 400 €, z toho 0 € na bývanie. */
    protected function studentSpending(float $housing = 0): void
    {
        $account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 500, 'color' => '#4c8dff']);
        $group = Category::create(['user_id' => $this->user->id, 'name' => 'Bývanie', 'type' => 'expense', 'color' => '#fd7e14', 'icon' => '🏠', 'position' => 1]);
        $rent = Category::create(['user_id' => $this->user->id, 'name' => 'Nájom', 'type' => 'expense', 'parent_id' => $group->id, 'color' => '#fd7e14', 'icon' => '🔑', 'position' => 1]);
        $food = Category::create(['user_id' => $this->user->id, 'name' => 'Potraviny', 'type' => 'expense', 'color' => '#ff922b', 'icon' => '🛒', 'position' => 2]);

        for ($i = 1; $i <= 2; $i++) {
            $date = CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(3)->toDateString();
            Transaction::create(['user_id' => $this->user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 800, 'date' => $date]);
            Transaction::create(['user_id' => $this->user->id, 'account_id' => $account->id, 'category_id' => $food->id, 'type' => 'expense', 'amount' => 400 - $housing, 'date' => $date]);
            if ($housing > 0) {
                Transaction::create(['user_id' => $this->user->id, 'account_id' => $account->id, 'category_id' => $rent->id, 'type' => 'expense', 'amount' => $housing, 'date' => $date]);
            }
        }
    }

    public function test_without_housing_costs_the_page_warns_that_spending_is_too_low(): void
    {
        $this->studentSpending();

        $this->actingAs($this->user)->get('/retirement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('spending_basis.housing_now', 0)
                ->where('spending_basis.missing_housing', true)
                ->where('spending_basis.auto', 400)
                ->where('plan.spending', null)
            );
    }

    public function test_future_housing_replaces_todays_in_the_retirement_spending(): void
    {
        $this->studentSpending(housing: 100);

        $r = $this->actingAs($this->user)->getJson('/retirement/simulate?year=2065&monthly=100&housing=700')->assertOk();

        // 400 € spotreba − 100 € dnešné bývanie + 700 € neskôr
        $this->assertEquals(1_000, $r->json('freedom.monthly_spending'));
    }

    public function test_an_own_spending_figure_beats_the_automatic_one(): void
    {
        $this->studentSpending();

        $r = $this->actingAs($this->user)->getJson('/retirement/simulate?year=2065&monthly=100&housing=700&spending=1800')->assertOk();

        $this->assertEquals(1_800, $r->json('freedom.monthly_spending'));
    }

    // ── Pravidlo vkladu a miera výberu ──────────────────────────────────

    public function test_the_recommended_withdrawal_rate_follows_the_retirement_length(): void
    {
        $svc = app(RetirementService::class);

        // bez roku narodenia klasických 4 %
        $this->assertSame(4.0, $svc->recommendedWithdrawal(null, 2065)['rate']);
        // odchod v 65 → do 90 je to 25 rokov
        $this->assertSame(4.0, $svc->recommendedWithdrawal(2000, 2065)['rate']);
        // odchod v 55 → 35 rokov
        $this->assertSame(3.5, $svc->recommendedWithdrawal(2004, 2059)['rate']);
        // odchod v 45 → 45 rokov
        $this->assertSame(3.25, $svc->recommendedWithdrawal(2004, 2049)['rate']);
    }

    public function test_an_empty_withdrawal_rate_uses_the_recommended_one(): void
    {
        $this->user->update(['retire_birth_year' => 2004, 'retire_withdrawal' => null]);

        $p = app(RetirementService::class)->resolveParams($this->user->fresh(), ['year' => 2059]);

        $this->assertSame(3.5, $p['withdrawal']);
        $this->assertTrue($p['withdrawal_is_recommended']);
    }

    public function test_the_contribution_can_be_a_share_of_income_after_fixed_payments(): void
    {
        $account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 0, 'color' => '#4c8dff']);
        for ($i = 1; $i <= 3; $i++) {
            Transaction::create(['user_id' => $this->user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 2_000,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(3)->toDateString()]);
        }
        $this->user->loans()->create([
            'kind' => 'owe', 'name' => 'Lízing', 'balance' => 9_000, 'principal' => 15_000, 'payment' => 300, 'rate' => 5,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);

        $r = $this->actingAs($this->user)
            ->getJson('/retirement/simulate?year=2065&contribution_mode=percent&contribution_pct=25')
            ->assertOk();

        // 25 % z (2 000 − 300)
        $this->assertEquals(425, $r->json('rule.monthly'));
        $this->assertEquals(425, $r->json('params.monthly'));
    }

    public function test_a_later_phase_raises_the_contribution_from_that_year(): void
    {
        $this->user->loans()->create([
            'kind' => 'owe', 'name' => 'Lízing', 'balance' => 9_000, 'principal' => 15_000, 'payment' => 200, 'rate' => 5,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);
        $from = CarbonImmutable::today()->year + 3;

        $r = $this->actingAs($this->user)
            ->getJson("/retirement/simulate?year=2065&housing=600&contribution_mode=percent&contribution_pct=30&contribution_later_from=$from&contribution_later_income=1800")
            ->assertOk();

        // povinné platby neskôr = splátky 200 + bývanie 600; 30 % z (1 800 − 800)
        $this->assertEquals(800, $r->json('rule.later.fixed'));
        $this->assertEquals(300, $r->json('rule.later.monthly'));
        $this->assertSame($from, $r->json('params.later_from'));
    }

    public function test_a_two_phase_plan_grows_with_the_later_contribution(): void
    {
        $svc = app(RetirementService::class);
        $year = CarbonImmutable::today()->year;
        $p = $svc->resolveParams($this->user, ['year' => $year + 20, 'monthly' => 100, 'monthly_later' => 500, 'later_from' => $year + 5]);

        // 5 rokov po 100 €, potom 15 rokov po 500 € (pri nulovom výnose len súčet)
        $this->assertSame(100.0 * 60 + 500.0 * 180, $svc->valueAtPlan(0, $p, 0.0, 20));
        $this->assertGreaterThan($svc->valueAt(0, 100, 5.0, 20), $svc->valueAtPlan(0, $p, 5.0, 20));
    }

    public function test_the_missing_contribution_is_asked_for_the_later_phase(): void
    {
        $year = CarbonImmutable::today()->year;
        $r = $this->project(0, ['year' => $year + 25, 'monthly' => 50, 'monthly_later' => 100, 'later_from' => $year + 4, 'spending' => 2_000]);

        $this->assertFalse($r['freedom']['within_plan']);
        $this->assertSame($year + 4, $r['freedom']['required_from']);

        $hit = $this->project(0, ['year' => $year + 25, 'monthly' => 50, 'monthly_later' => $r['freedom']['required_monthly'], 'later_from' => $year + 4, 'spending' => 2_000]);
        $this->assertTrue($hit['freedom']['within_plan']);
    }

    public function test_the_contribution_rule_is_saved(): void
    {
        $this->actingAs($this->user)->post('/retirement', [
            'year' => 2060, 'birth_year' => 2004, 'monthly' => 0, 'withdrawal' => null,
            'contribution_mode' => 'percent', 'contribution_pct' => 28,
            'contribution_later_from' => 2029, 'contribution_later_income' => 1_500,
        ])->assertRedirect();

        $u = $this->user->fresh();
        $this->assertSame(2004, $u->retire_birth_year);
        $this->assertNull($u->retire_withdrawal);
        $this->assertSame('percent', $u->retire_contribution['mode']);
        $this->assertEquals(28, $u->retire_contribution['pct']);
        $this->assertEquals(2029, $u->retire_contribution['later_from']);
    }

    public function test_a_portfolio_outside_etfs_is_reported(): void
    {
        foreach ([['VWCE', 'etf', 700], ['BTC', 'crypto', 300]] as [$ticker, $kind, $price]) {
            $inv = Investment::create(['user_id' => $this->user->id, 'ticker' => $ticker, 'name' => $ticker, 'kind' => $kind,
                'quote_source' => 'manual', 'current_price' => $price, 'color' => '#4c8dff']);
            $inv->lots()->create(['type' => 'buy', 'units' => 1, 'price' => $price, 'date' => CarbonImmutable::today()->subMonth()->toDateString()]);
            $inv->recomputeFromLots();
        }

        $this->actingAs($this->user)->get('/retirement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('allocation.crypto_pct', 30));
    }

    // ── Krypto a priemerná mzda ─────────────────────────────────────────

    public function test_crypto_is_left_out_by_default_and_can_be_counted_two_ways(): void
    {
        $year = CarbonImmutable::today()->year + 20;
        $base = ['year' => $year, 'monthly' => 0, 'crypto_value' => 4_000];
        $mid = fn (array $r) => collect($r['scenarios'])->firstWhere('key', 'mid')['final'];

        // portfólio 10 000 €, z toho 4 000 € krypto
        $exclude = $this->project(10_000, $base);
        $flat = $this->project(10_000, $base + ['crypto_mode' => 'flat']);
        $stocks = $this->project(10_000, $base + ['crypto_mode' => 'stocks']);

        $this->assertSame('exclude', $exclude['crypto']['mode']);
        $this->assertEqualsWithDelta(6_000 * 1.05 ** 20, $mid($exclude), 1);
        $this->assertEqualsWithDelta(6_000 * 1.05 ** 20 + 4_000, $mid($flat), 1);
        $this->assertEqualsWithDelta(10_000 * 1.05 ** 20, $mid($stocks), 1);
    }

    public function test_the_page_compares_every_way_of_counting_crypto(): void
    {
        $inv = Investment::create(['user_id' => $this->user->id, 'ticker' => 'BTC', 'name' => 'Bitcoin', 'kind' => 'crypto',
            'quote_source' => 'manual', 'current_price' => 50_000, 'color' => '#f7931a']);
        $inv->lots()->create(['type' => 'buy', 'units' => 0.2, 'price' => 40_000, 'date' => CarbonImmutable::today()->subYear()->toDateString()]);
        $inv->recomputeFromLots();

        $r = $this->actingAs($this->user)->getJson('/retirement/simulate?year=2060&monthly=200&spending=1500&crypto_mode=flat')->assertOk();

        $this->assertEquals(10_000, $r->json('crypto.value'));
        $this->assertSame('flat', $r->json('crypto.mode'));
        $this->assertEqualsCanonicalizing(['exclude', 'flat', 'stocks'], array_keys($r->json('crypto_alternatives')));
        $this->assertLessThanOrEqual($r->json('crypto_alternatives.exclude.freedom_year'), $r->json('crypto_alternatives.stocks.freedom_year'));
    }

    public function test_the_average_wage_reference_is_available_to_the_plan(): void
    {
        $this->actingAs($this->user)->get('/retirement')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('averageWage.gross', 1_620)
                ->where('averageWage.net_estimate', 1_218)
                ->where('plan.crypto_mode', 'exclude')
            );
    }
}
