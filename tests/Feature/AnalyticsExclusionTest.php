<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\FinanceService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
        $this->category = Category::create(['user_id' => $this->user->id, 'name' => 'Potraviny', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'cart', 'position' => 1]);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function txn(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $attrs));
    }

    protected function thisMonth(): Period
    {
        return new Period('month', CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfMonth(), 'test');
    }

    public function test_excluded_transaction_is_left_out_of_summary_and_categories(): void
    {
        $this->txn(['amount' => 100]);
        $this->txn(['amount' => 40, 'excluded_from_analytics' => true, 'exclusion_reason' => 'Preplatené firmou']);

        $analytics = app(AnalyticsService::class);
        $summary = $analytics->summary($this->user, $this->thisMonth());

        $this->assertSame(100.0, $summary['expense']);
        $this->assertSame(1, $summary['count']);
        $this->assertSame(100.0, $analytics->byCategory($this->user, $this->thisMonth(), 'expense')->first()['amount']);
    }

    public function test_excluded_transaction_is_left_out_of_budget_spending(): void
    {
        Budget::create(['user_id' => $this->user->id, 'category_id' => $this->category->id, 'limit_amount' => 500, 'period' => 'month']);

        $start = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->txn(['amount' => 100, 'date' => $start]);
        $this->txn(['amount' => 300, 'date' => $start, 'excluded_from_analytics' => true, 'exclusion_reason' => 'Vrátené peniaze']);

        $this->assertSame(100.0, app(FinanceService::class)->budgetProgress($this->user)->first()['spent']);
    }

    public function test_transaction_can_be_excluded_with_a_reason(): void
    {
        $t = $this->txn();

        $this->actingAs($this->user)
            ->patch("/transactions/{$t->id}/exclusion", ['excluded_from_analytics' => true, 'exclusion_reason' => 'Preplatené firmou'])
            ->assertRedirect();

        $this->assertTrue($t->fresh()->excluded_from_analytics);
        $this->assertSame('Preplatené firmou', $t->fresh()->exclusion_reason);
    }

    public function test_exclusion_requires_a_reason(): void
    {
        $t = $this->txn();

        $this->actingAs($this->user)
            ->patch("/transactions/{$t->id}/exclusion", ['excluded_from_analytics' => true])
            ->assertSessionHasErrors('exclusion_reason');

        $this->assertFalse($t->fresh()->excluded_from_analytics);
    }

    public function test_returning_to_analytics_clears_the_reason(): void
    {
        $t = $this->txn(['excluded_from_analytics' => true, 'exclusion_reason' => 'Chyba']);

        $this->actingAs($this->user)
            ->patch("/transactions/{$t->id}/exclusion", ['excluded_from_analytics' => false])
            ->assertRedirect();

        $this->assertFalse($t->fresh()->excluded_from_analytics);
        $this->assertNull($t->fresh()->exclusion_reason);
    }

    public function test_foreign_transaction_cannot_be_excluded(): void
    {
        $t = $this->txn();

        $this->actingAs(User::factory()->create())
            ->patch("/transactions/{$t->id}/exclusion", ['excluded_from_analytics' => true, 'exclusion_reason' => 'x'])
            ->assertForbidden();
    }

    public function test_exclusion_can_be_set_when_creating_a_transaction(): void
    {
        $this->actingAs($this->user)
            ->post('/transactions', [
                'type' => 'expense',
                'amount' => 25,
                'account_id' => $this->account->id,
                'category_id' => $this->category->id,
                'date' => CarbonImmutable::today()->toDateString(),
                'excluded_from_analytics' => true,
                'exclusion_reason' => 'Preplatené firmou',
            ])
            ->assertRedirect();

        $t = Transaction::latest('id')->first();

        $this->assertTrue($t->excluded_from_analytics);
        $this->assertSame('Preplatené firmou', $t->exclusion_reason);
        // zostatok účtu sa mení aj tak — peniaze naozaj odišli
        $this->assertSame(975.0, (float) $this->account->fresh()->balance);
    }
}
