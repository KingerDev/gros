<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuePaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
    }

    protected function subscription(string $nextPayment, float $amount = 4.99): Subscription
    {
        return Subscription::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'name' => 'YouTube Premium',
            'amount' => $amount,
            'cycle' => 'monthly',
            'next_payment' => $nextPayment,
            'color' => '#e8544e',
        ]);
    }

    public function test_due_subscription_is_posted_when_a_page_is_loaded(): void
    {
        $sub = $this->subscription(CarbonImmutable::today()->subDays(11)->toDateString());

        $this->actingAs($this->user)->get('/transactions')->assertOk();

        $t = Transaction::where('note', 'YouTube Premium')->first();

        $this->assertNotNull($t);
        $this->assertSame('expense', $t->type);
        $this->assertSame(995.01, (float) $this->account->fresh()->balance);
        // označená ako automatická, s odkazom na zdroj
        $this->assertSame('subscription', $t->source);
        $this->assertSame($sub->id, (int) $t->source_id);
        // ďalší termín je posunutý do budúcnosti
        $this->assertTrue($sub->fresh()->next_payment->isFuture());
    }

    public function test_manually_created_transaction_has_no_source(): void
    {
        $category = Category::create(['user_id' => $this->user->id, 'name' => 'Potraviny', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'cart', 'position' => 1]);

        $this->actingAs($this->user)->post('/transactions', [
            'type' => 'expense',
            'amount' => 25,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
            'date' => CarbonImmutable::today()->toDateString(),
            // pokus podvrhnúť zdroj cez request sa nesmie prejaviť
            'source' => 'subscription',
            'source_id' => 999,
        ])->assertRedirect();

        $t = Transaction::latest('id')->first();

        $this->assertNull($t->source);
        $this->assertNull($t->source_id);
    }

    public function test_editing_an_automatic_transaction_keeps_its_source(): void
    {
        $sub = $this->subscription(CarbonImmutable::today()->toDateString());
        $category = Category::create(['user_id' => $this->user->id, 'name' => 'Streaming', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'tv', 'position' => 1]);

        $this->actingAs($this->user)->get('/transactions')->assertOk();
        $t = Transaction::where('source', 'subscription')->firstOrFail();

        $this->actingAs($this->user)->put("/transactions/{$t->id}", [
            'type' => 'expense',
            'amount' => 5.99,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
            'date' => $t->date->toDateString(),
            'note' => 'YouTube Premium',
        ])->assertRedirect();

        $this->assertSame('subscription', $t->fresh()->source);
        $this->assertSame($sub->id, (int) $t->fresh()->source_id);
    }

    public function test_repeated_page_loads_do_not_duplicate_the_payment(): void
    {
        $this->subscription(CarbonImmutable::today()->toDateString());

        $this->actingAs($this->user)->get('/transactions')->assertOk();
        $this->actingAs($this->user)->get('/transactions')->assertOk();
        $this->actingAs($this->user)->get('/accounts')->assertOk();

        $this->assertSame(1, Transaction::where('note', 'YouTube Premium')->count());
        $this->assertSame(995.01, (float) $this->account->fresh()->balance);
    }

    public function test_future_subscription_is_not_posted(): void
    {
        $this->subscription(CarbonImmutable::today()->addDay()->toDateString());

        $this->actingAs($this->user)->get('/transactions')->assertOk();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(1000.0, (float) $this->account->fresh()->balance);
    }

    public function test_subscription_without_an_account_is_not_posted(): void
    {
        Subscription::create([
            'user_id' => $this->user->id,
            'account_id' => null,
            'name' => 'Netflix',
            'amount' => 12.99,
            'cycle' => 'monthly',
            'next_payment' => CarbonImmutable::today()->toDateString(),
            'color' => '#e8544e',
        ]);

        $this->actingAs($this->user)->get('/transactions')->assertOk();

        $this->assertSame(0, Transaction::count());
    }

    public function test_due_loan_instalment_is_posted_and_lowers_the_balance(): void
    {
        $loan = Loan::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'name' => 'Auto',
            'kind' => 'owe',
            'balance' => 18400,
            'payment' => 400,
            'next_payment' => CarbonImmutable::today()->subDays(5)->toDateString(),
            'color' => '#e8544e',
        ]);

        $this->actingAs($this->user)->get('/transactions')->assertOk();

        $this->assertSame(1, Transaction::where('note', 'Auto')->count());
        $this->assertSame(18000.0, (float) $loan->fresh()->balance);
        $this->assertSame(600.0, (float) $this->account->fresh()->balance);
    }

    public function test_payments_of_other_users_are_not_touched(): void
    {
        $other = User::factory()->create();
        $otherAccount = Account::create(['user_id' => $other->id, 'name' => 'Iný', 'type' => 'cash', 'balance' => 500, 'color' => '#4c8dff']);
        Subscription::create([
            'user_id' => $other->id,
            'account_id' => $otherAccount->id,
            'name' => 'Spotify',
            'amount' => 9.99,
            'cycle' => 'monthly',
            'next_payment' => CarbonImmutable::today()->toDateString(),
            'color' => '#e8544e',
        ]);

        $this->actingAs($this->user)->get('/transactions')->assertOk();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(500.0, (float) $otherAccount->fresh()->balance);
    }
}
