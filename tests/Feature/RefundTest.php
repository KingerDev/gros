<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\FinanceService;
use App\Services\SpendingPlanService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $category;

    protected Category $incomeCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
        $this->category = Category::create(['user_id' => $this->user->id, 'name' => 'Oblečenie', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'shirt', 'position' => 1]);
        $this->incomeCategory = Category::create(['user_id' => $this->user->id, 'name' => 'Výplata', 'type' => 'income', 'color' => '#2ba35a', 'icon' => 'wallet', 'position' => 1]);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function expense(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 300,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    protected function income(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->incomeCategory->id,
            'type' => 'income',
            'amount' => 200,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $attrs));
    }

    protected function thisMonth(): Period
    {
        return new Period('month', CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfMonth(), 'test');
    }

    public function test_refund_lowers_the_expense_in_analytics_but_is_not_income(): void
    {
        $purchase = $this->expense(['amount' => 300]);

        $this->actingAs($this->user)
            ->post("/transactions/{$purchase->id}/refunds", [
                'amount' => 200,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
                'note' => 'Vrátené tričko',
            ])
            ->assertRedirect();

        $analytics = app(AnalyticsService::class);
        $summary = $analytics->summary($this->user, $this->thisMonth());

        // 300 € nákup − 200 € vrátené = 100 € reálne minuté
        $this->assertSame(100.0, $summary['expense']);
        // vrátenie sa neráta ako príjem
        $this->assertSame(0.0, $summary['income']);
        $this->assertSame(100.0, $analytics->byCategory($this->user, $this->thisMonth(), 'expense')->first()['amount']);
        $this->assertSame(200.0, (float) $purchase->fresh()->refunded_amount);
    }

    public function test_refund_puts_the_money_back_on_the_account(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $this->account->decrement('balance', 300); // nákup už zostatok znížil

        $this->actingAs($this->user)
            ->post("/transactions/{$purchase->id}/refunds", [
                'amount' => 200,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(900.0, (float) $this->account->fresh()->balance);
    }

    public function test_existing_income_can_be_paired_as_a_refund(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $incoming = $this->income(['amount' => 200]);

        $this->actingAs($this->user)
            ->patch("/transactions/{$incoming->id}/refund-link", ['refund_for_id' => $purchase->id])
            ->assertRedirect();

        $incoming->refresh();
        $this->assertSame($purchase->id, $incoming->refund_for_id);
        // vrátenie patrí do kategórie pôvodného nákupu, vlastnú nemá
        $this->assertNull($incoming->category_id);
        $this->assertSame(200.0, (float) $purchase->fresh()->refunded_amount);

        $summary = app(AnalyticsService::class)->summary($this->user, $this->thisMonth());
        $this->assertSame(100.0, $summary['expense']);
        $this->assertSame(0.0, $summary['income']);
    }

    public function test_unpairing_turns_the_refund_back_into_plain_income(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $incoming = $this->income(['amount' => 200]);

        $this->actingAs($this->user)->patch("/transactions/{$incoming->id}/refund-link", ['refund_for_id' => $purchase->id]);
        $this->actingAs($this->user)
            ->patch("/transactions/{$incoming->id}/refund-link", ['refund_for_id' => null])
            ->assertRedirect();

        $this->assertNull($incoming->fresh()->refund_for_id);
        $this->assertSame(0.0, (float) $purchase->fresh()->refunded_amount);

        $summary = app(AnalyticsService::class)->summary($this->user, $this->thisMonth());
        $this->assertSame(300.0, $summary['expense']);
        $this->assertSame(200.0, $summary['income']);
    }

    public function test_partial_refunds_add_up(): void
    {
        $purchase = $this->expense(['amount' => 300]);

        foreach ([120, 80] as $amount) {
            $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
                'amount' => $amount,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])->assertRedirect();
        }

        $this->assertSame(200.0, (float) $purchase->fresh()->refunded_amount);
        $this->assertSame(100.0, app(AnalyticsService::class)->summary($this->user, $this->thisMonth())['expense']);
    }

    public function test_refund_cannot_exceed_the_original_expense(): void
    {
        $purchase = $this->expense(['amount' => 300]);

        $this->actingAs($this->user)
            ->post("/transactions/{$purchase->id}/refunds", [
                'amount' => 301,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0.0, (float) $purchase->fresh()->refunded_amount);
    }

    public function test_refund_cannot_be_paired_to_an_income(): void
    {
        $salary = $this->income(['amount' => 900]);
        $incoming = $this->income(['amount' => 200]);

        $this->actingAs($this->user)
            ->patch("/transactions/{$incoming->id}/refund-link", ['refund_for_id' => $salary->id])
            ->assertSessionHasErrors('refund_for_id');
    }

    public function test_expense_cannot_be_paired_as_a_refund(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $other = $this->expense(['amount' => 50]);

        $this->actingAs($this->user)
            ->patch("/transactions/{$other->id}/refund-link", ['refund_for_id' => $purchase->id])
            ->assertSessionHasErrors('refund_for_id');
    }

    public function test_foreign_transaction_cannot_be_refunded(): void
    {
        $purchase = $this->expense();

        $this->actingAs(User::factory()->create())
            ->post("/transactions/{$purchase->id}/refunds", [
                'amount' => 10,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_deleting_a_refund_restores_the_full_expense(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
            'amount' => 200,
            'account_id' => $this->account->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $refund = Transaction::query()->whereNotNull('refund_for_id')->firstOrFail();

        $this->actingAs($this->user)->delete("/transactions/{$refund->id}")->assertRedirect();

        $this->assertSame(0.0, (float) $purchase->fresh()->refunded_amount);
        $this->assertSame(300.0, app(AnalyticsService::class)->summary($this->user, $this->thisMonth())['expense']);
    }

    public function test_editing_a_refund_amount_updates_the_expense(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
            'amount' => 200,
            'account_id' => $this->account->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $refund = Transaction::query()->whereNotNull('refund_for_id')->firstOrFail();

        $this->actingAs($this->user)
            ->put("/transactions/{$refund->id}", [
                'type' => 'income',
                'amount' => 150,
                'account_id' => $this->account->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertSame(150.0, (float) $purchase->fresh()->refunded_amount);
        $this->assertSame(150.0, app(AnalyticsService::class)->summary($this->user, $this->thisMonth())['expense']);
    }

    public function test_expense_cannot_be_edited_below_what_was_already_refunded(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
            'amount' => 200,
            'account_id' => $this->account->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->put("/transactions/{$purchase->id}", [
                'type' => 'expense',
                'amount' => 150,
                'account_id' => $this->account->id,
                'category_id' => $this->category->id,
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(300.0, (float) $purchase->fresh()->amount);
    }

    public function test_budget_and_spending_plan_count_the_net_amount(): void
    {
        Budget::create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'limit_amount' => 500, 'period' => 'month']);

        $start = CarbonImmutable::today()->startOfMonth()->toDateString();
        $purchase = $this->expense(['amount' => 300, 'date' => $start]);

        $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
            'amount' => 200,
            'account_id' => $this->account->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->assertSame(100.0, app(FinanceService::class)->budgetProgress($this->user)->first()['spent']);
        $this->assertSame(100.0, app(SpendingPlanService::class)->current($this->user)['spent']);
    }

    public function test_deleting_the_purchase_leaves_the_refund_as_plain_income(): void
    {
        $purchase = $this->expense(['amount' => 300]);
        $this->actingAs($this->user)->post("/transactions/{$purchase->id}/refunds", [
            'amount' => 200,
            'account_id' => $this->account->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($this->user)->delete("/transactions/{$purchase->id}")->assertRedirect();

        $refund = Transaction::query()->where('type', 'income')->firstOrFail();
        $this->assertNull($refund->refund_for_id);
        $this->assertSame(200.0, app(AnalyticsService::class)->summary($this->user, $this->thisMonth())['income']);
    }
}
