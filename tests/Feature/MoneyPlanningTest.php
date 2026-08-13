<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoanPlanService;
use App\Services\OpportunityCostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doživotná cena výdavkov a plán splácania úverov — tie časti appky, ktoré
 * prekladajú bežné rozhodnutia do rokov práce.
 */
class MoneyPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['retire_year' => CarbonImmutable::today()->year + 30]);
    }

    // ── Doživotná cena ──────────────────────────────────────────────────

    public function test_monthly_amount_compounds_into_its_lifetime_cost(): void
    {
        $cost = app(OpportunityCostService::class);

        // 100 € mesačne, 10 rokov, 6 % ročne. Mesačná sadzba je efektívna
        // (1,06^(1/12)), nie 6/12 — preto ~16 250, nie ~16 390.
        $this->assertEqualsWithDelta(16_247, $cost->fromMonthly(100, 10, 6.0), 30);

        // pri nulovom výnose je to len súčet vkladov
        $this->assertSame(12_000.0, $cost->fromMonthly(100, 10, 0.0));

        $this->assertSame(0.0, $cost->fromMonthly(100, 0, 6.0));
        $this->assertSame(0.0, $cost->fromMonthly(0, 10, 6.0));
    }

    public function test_lump_sum_compounds_at_the_real_rate(): void
    {
        $this->assertEqualsWithDelta(1_790.85, app(OpportunityCostService::class)->fromLumpSum(1_000, 10, 6.0), 0.5);
    }

    public function test_every_subscription_gets_its_lifetime_price(): void
    {
        Subscription::create([
            'user_id' => $this->user->id, 'name' => 'Netflix', 'amount' => 15,
            'cycle' => 'monthly', 'next_payment' => CarbonImmutable::today()->toDateString(), 'color' => '#e8544e',
        ]);
        // ročné predplatné sa musí normalizovať na mesiac
        $yearly = Subscription::create([
            'user_id' => $this->user->id, 'name' => 'Doména', 'amount' => 120,
            'cycle' => 'yearly', 'next_payment' => CarbonImmutable::today()->toDateString(), 'color' => '#4c8dff',
        ]);

        $rows = app(OpportunityCostService::class)->subscriptions($this->user);

        $this->assertCount(2, $rows);
        $this->assertSame(10.0, $rows[$yearly->id]['monthly']);
        foreach ($rows as $row) {
            // 30 rokov zhodnocovania musí byť násobne viac než súčet vkladov
            $this->assertGreaterThan($row['monthly'] * 12 * 30, $row['lifetime']);
        }
    }

    public function test_category_lifetime_cost_uses_completed_months_only(): void
    {
        $account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 0, 'color' => '#4c8dff']);
        $category = Category::create(['user_id' => $this->user->id, 'name' => 'Reštaurácie', 'type' => 'expense', 'color' => '#ff922b', 'icon' => '🍔', 'position' => 1]);

        // 200 € mesačne po dobu 12 ukončených mesiacov
        for ($i = 1; $i <= 12; $i++) {
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $account->id, 'category_id' => $category->id,
                'type' => 'expense', 'amount' => 200,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(3)->toDateString(),
            ]);
        }
        // prebiehajúci mesiac sa počítať nesmie
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $account->id, 'category_id' => $category->id,
            'type' => 'expense', 'amount' => 9_999, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $rows = app(OpportunityCostService::class)->categories($this->user, 12, 6);

        $this->assertCount(1, $rows);
        $this->assertSame($category->id, $rows[0]['category_id']);
        $this->assertSame(200.0, $rows[0]['monthly']);
        $this->assertGreaterThan(200 * 12 * 30, $rows[0]['lifetime']);
    }

    // ── Plán úverov ─────────────────────────────────────────────────────

    public function test_interest_free_loan_pays_off_by_simple_division(): void
    {
        $loan = $this->loan(balance: 1_200, payment: 100, rate: 0);

        $plan = app(LoanPlanService::class)->forLoan($loan, 100, 6.0);

        $this->assertTrue($plan['ok']);
        $this->assertSame(12, $plan['months']);
        $this->assertSame(0.0, $plan['total_interest']);
        // bez úroku niet čo ušetriť, takže investovanie vyhráva vždy
        $this->assertSame('invest', $plan['compare']['verdict']);
    }

    public function test_amortisation_matches_a_known_mortgage(): void
    {
        // 100 000 € pri 5 % a splátke 536,82 € je klasická 30-ročná hypotéka
        $loan = $this->loan(balance: 100_000, payment: 536.82, rate: 5.0);

        $plan = app(LoanPlanService::class)->forLoan($loan, 100, 6.0);

        $this->assertTrue($plan['ok']);
        $this->assertEqualsWithDelta(360, $plan['months'], 1);
        $this->assertEqualsWithDelta(93_255, $plan['total_interest'], 500);
        $this->assertEqualsWithDelta(193_255, $plan['total_paid'], 500);
    }

    public function test_extra_payment_shortens_the_loan_and_saves_interest(): void
    {
        $loan = $this->loan(balance: 20_000, payment: 300, rate: 8.0);

        $plan = app(LoanPlanService::class)->forLoan($loan, 200, 5.0);

        $this->assertLessThan($plan['months'], $plan['with_extra']['months']);
        $this->assertGreaterThan(0, $plan['with_extra']['months_saved']);
        $this->assertGreaterThan(0, $plan['with_extra']['interest_saved']);
        // úrok 8 % je vyšší než reálny výnos 5 % → splácať sa oplatí viac
        $this->assertSame('repay', $plan['compare']['verdict']);
        // obe stratégie sa merajú na rovnakom horizonte
        $this->assertSame($plan['months'], $plan['compare']['horizon_months']);
    }

    public function test_a_cheap_loan_loses_to_investing(): void
    {
        $loan = $this->loan(balance: 20_000, payment: 300, rate: 1.0);

        $plan = app(LoanPlanService::class)->forLoan($loan, 200, 7.0);

        $this->assertSame('invest', $plan['compare']['verdict']);
        $this->assertGreaterThan(0, $plan['compare']['advantage']);
    }

    public function test_the_two_strategies_are_measured_on_the_same_horizon(): void
    {
        $loan = $this->loan(balance: 20_000, payment: 300, rate: 8.0);
        $plan = app(LoanPlanService::class)->forLoan($loan, 200, 5.0);

        // pri nulovom výnose je porovnanie čistá aritmetika: skoršie splatenie
        // uvoľní (splátka + extra) na zvyšok horizontu
        $flat = app(LoanPlanService::class)->forLoan($loan, 200, 0.0);
        $freed = $plan['months'] - $plan['with_extra']['months'];

        $this->assertSame(round(500.0 * $freed, 2), $flat['compare']['repay_first']);
        $this->assertSame(round(200.0 * $plan['months'], 2), $flat['compare']['invest_first']);
    }

    public function test_a_payment_smaller_than_the_interest_is_reported_not_amortised(): void
    {
        // 10 € mesačne pri 10 % z 20 000 € nepokryje ani mesačný úrok (166 €)
        $loan = $this->loan(balance: 20_000, payment: 10, rate: 10.0);

        $plan = app(LoanPlanService::class)->forLoan($loan, 100, 6.0);

        $this->assertFalse($plan['ok']);
        $this->assertNotEmpty($plan['reason']);
    }

    public function test_the_priciest_debt_is_flagged_as_priority(): void
    {
        $this->loan(balance: 5_000, payment: 200, rate: 3.0);
        $expensive = $this->loan(balance: 3_000, payment: 150, rate: 14.0);

        $plan = app(LoanPlanService::class)->forUser($this->user, 100);

        $this->assertSame($expensive->id, $plan['priority_id']);
        $this->assertCount(2, $plan['loans']);
    }

    public function test_loans_page_renders_with_the_plan(): void
    {
        $this->loan(balance: 5_000, payment: 200, rate: 3.0);

        $this->actingAs($this->user)->get('/loans?extra=250')->assertOk();
    }

    protected function loan(float $balance, float $payment, float $rate): Loan
    {
        return Loan::create([
            'user_id' => $this->user->id,
            'kind' => 'owe',
            'name' => 'Úver '.$rate.'%',
            'balance' => $balance,
            'principal' => $balance,
            'payment' => $payment,
            'rate' => $rate,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(),
            'color' => '#4c8dff',
        ]);
    }
}
