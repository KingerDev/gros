<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\NetWorthService;
use App\Services\RecurringPaymentService;
use App\Services\SpendingPlanService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Koľko môžem ešte minúť" a čísla, z ktorých vychádza — fixné platby,
 * príjem a dlh v čase.
 */
class SpendingPlanTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['monthly_income' => 2_000]);
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 3_000, 'color' => '#4c8dff']);
    }

    public function test_a_posted_subscription_and_loan_payment_are_not_subtracted_twice(): void
    {
        $today = CarbonImmutable::today();
        Subscription::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'name' => 'Netflix', 'amount' => 15,
            'cycle' => 'monthly', 'next_payment' => $today->startOfMonth()->toDateString(), 'color' => '#e8544e',
        ]);
        Loan::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'kind' => 'owe', 'name' => 'Auto',
            'balance' => 5_000, 'principal' => 10_000, 'payment' => 300, 'rate' => 0,
            'next_payment' => $today->startOfMonth()->toDateString(), 'color' => '#4c8dff',
        ]);

        $before = app(SpendingPlanService::class)->current($this->user->fresh());

        // obe platby sa v deň splatnosti zaúčtujú ako transakcie
        $this->assertSame(2, app(RecurringPaymentService::class)->process($today, $this->user));
        $after = app(SpendingPlanService::class)->current($this->user->fresh());

        $this->assertSame(315.0, $after['fixed']);
        $this->assertSame(0.0, $after['spent']);
        $this->assertSame($before['safeToSpend'], $after['safeToSpend']);
    }

    public function test_fixed_payments_do_not_inflate_the_projected_pace(): void
    {
        $today = CarbonImmutable::today();
        Loan::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'kind' => 'owe', 'name' => 'Hypotéka',
            'balance' => 80_000, 'principal' => 100_000, 'payment' => 600, 'rate' => 0,
            'next_payment' => $today->startOfMonth()->toDateString(), 'color' => '#4c8dff',
        ]);
        app(RecurringPaymentService::class)->process($today, $this->user);

        $plan = app(SpendingPlanService::class)->current($this->user->fresh());

        // 600 € zo začiatku mesiaca sa nepremietne do tempa ako 600 € každých pár dní
        $this->assertSame(0.0, $plan['projectedSpend']);
    }

    public function test_uncategorised_income_counts_even_when_savings_categories_exist(): void
    {
        Category::create(['user_id' => $this->user->id, 'name' => 'Investície', 'type' => 'expense', 'is_savings' => true, 'color' => '#f06595', 'icon' => '📊', 'position' => 1]);
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'category_id' => null,
            'type' => 'income', 'amount' => 1_000, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $period = new Period('month', CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfMonth(), 'test');

        // NOT IN by riadok bez kategórie (NULL) ticho vyradil
        $this->assertSame(1_000.0, app(AnalyticsService::class)->summary($this->user, $period)['income']);
    }

    public function test_a_repaid_loan_i_gave_is_not_income(): void
    {
        $today = CarbonImmutable::today();
        Loan::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'kind' => 'lent', 'name' => 'Kamarát',
            'balance' => 1_000, 'principal' => 1_000, 'payment' => 200, 'rate' => 0,
            'next_payment' => $today->toDateString(), 'color' => '#2ba35a',
        ]);
        app(RecurringPaymentService::class)->process($today, $this->user);

        $period = new Period('month', $today->startOfMonth(), $today->endOfMonth(), 'test');

        $this->assertSame(0.0, app(AnalyticsService::class)->summary($this->user, $period)['income']);
    }

    public function test_past_debt_is_rebuilt_with_the_interest_part_of_each_payment(): void
    {
        Loan::create([
            'user_id' => $this->user->id, 'kind' => 'owe', 'name' => 'Úver',
            'balance' => 10_000, 'principal' => 50_000, 'payment' => 500, 'rate' => 12.0,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'type' => 'expense', 'amount' => 1,
            'date' => CarbonImmutable::today()->subMonthsNoOverflow(1)->startOfMonth()->toDateString(),
        ]);

        $series = app(NetWorthService::class)->monthlySeries($this->user);
        $prev = $series[count($series) - 2];

        // pred mesiacom: (10 000 + 500) / 1,01 = 10 396 €, nie 10 500 €
        $this->assertEqualsWithDelta(10_396.04, $prev['debt'], 0.01);
    }
}
