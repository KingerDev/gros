<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Event;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\EmergencyFundService;
use App\Services\EventService;
use App\Services\ExpenseClassifier;
use App\Services\FinanceService;
use App\Services\FinancialProfileService;
use App\Services\SpendingPlanService;
use App\Support\Period;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Udalosti (dovolenka…): výdavky sa rátajú do toho, koľko som minul,
 * ale nie do toho, koľko bežne míňam.
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $food;

    protected Category $salary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 5000, 'color' => '#4c8dff']);
        $this->food = Category::create(['user_id' => $this->user->id, 'name' => 'Reštaurácie', 'type' => 'expense', 'color' => '#e8544e', 'position' => 1]);
        $this->salary = Category::create(['user_id' => $this->user->id, 'name' => 'Výplata', 'type' => 'income', 'color' => '#2ba35a', 'position' => 2]);
    }

    protected function event(array $attrs = []): Event
    {
        return $this->user->events()->create(array_merge([
            'name' => 'Dovolenka Dublin 2026',
            'starts_on' => CarbonImmutable::today()->startOfMonth()->addDays(2)->toDateString(),
            'ends_on' => CarbonImmutable::today()->startOfMonth()->addDays(8)->toDateString(),
            'color' => '#22b8cf',
            'icon' => '✈️',
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    protected function txn(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->food->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => CarbonImmutable::today()->startOfMonth()->addDays(3)->toDateString(),
        ], $attrs));
    }

    protected function thisMonth(): Period
    {
        return new Period('month', CarbonImmutable::today()->startOfMonth(), CarbonImmutable::today()->endOfMonth(), 'test');
    }

    public function test_event_spending_stays_in_period_totals_and_its_category(): void
    {
        $event = $this->event();
        $this->txn(['amount' => 100]);
        $this->txn(['amount' => 250, 'event_id' => $event->id]);

        $analytics = app(AnalyticsService::class);
        $summary = $analytics->summary($this->user, $this->thisMonth());

        $this->assertSame(350.0, $summary['expense']);
        $this->assertSame(250.0, $summary['events']);
        // jedlo v Dubline ostáva jedlom
        $this->assertSame(350.0, $analytics->byCategory($this->user, $this->thisMonth(), 'expense')->first()['amount']);
    }

    public function test_event_spending_does_not_draw_the_category_budget(): void
    {
        $event = $this->event();
        Budget::create(['user_id' => $this->user->id, 'category_id' => $this->food->id, 'limit_amount' => 300, 'period' => 'month']);

        $this->txn(['amount' => 80]);
        $this->txn(['amount' => 400, 'event_id' => $event->id]);

        $this->assertSame(80.0, app(FinanceService::class)->budgetProgress($this->user)->first()['spent']);
    }

    public function test_event_transactions_are_always_one_offs_even_small_ones(): void
    {
        $event = $this->event();
        $coffee = $this->txn(['amount' => 4.5, 'event_id' => $event->id]);
        $lunch = $this->txn(['amount' => 12]);

        $ids = app(ExpenseClassifier::class)->oneOffIds(
            $this->user->transactions()->get(['id', 'category_id', 'event_id', 'date', 'amount', 'refunded_amount']),
            6
        );

        $this->assertContains($coffee->id, $ids);
        $this->assertNotContains($lunch->id, $ids);
    }

    public function test_the_reserve_leaves_events_out_of_the_average_as_one_row(): void
    {
        $lastMonth = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $event = $this->event(['starts_on' => $lastMonth->addDays(5), 'ends_on' => $lastMonth->addDays(10)]);

        $this->txn(['amount' => 300, 'date' => $lastMonth->addDay()]);
        $this->txn(['amount' => 40, 'date' => $lastMonth->addDays(6), 'event_id' => $event->id]);
        $this->txn(['amount' => 60, 'date' => $lastMonth->addDays(7), 'event_id' => $event->id]);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user, $fund->profile($this->user));

        $this->assertSame([], $expenses['one_offs']);
        $this->assertCount(1, $expenses['one_off_events']);
        $this->assertSame(100.0, $expenses['one_off_events'][0]['amount']);
        $this->assertSame($event->id, $expenses['one_off_events'][0]['id']);
    }

    public function test_the_savings_rate_treats_event_spending_as_one_off(): void
    {
        $lastMonth = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $event = $this->event(['starts_on' => $lastMonth, 'ends_on' => $lastMonth->addDays(5)]);

        $this->txn(['type' => 'income', 'category_id' => $this->salary->id, 'amount' => 2000, 'date' => $lastMonth]);
        // pod hranicou heuristiky (200 €) — jednorazový je len výdavok z udalosti
        $this->txn(['amount' => 150, 'date' => $lastMonth->addDays(2)]);
        $this->txn(['amount' => 30, 'date' => $lastMonth->addDays(3), 'event_id' => $event->id]);

        $measured = app(FinancialProfileService::class)->forUser($this->user)['measured'];

        $this->assertSame(180.0, $measured['expense']);
        $this->assertSame(30.0, $measured['one_off']);
        $this->assertSame(150.0, $measured['recurring_expense']);
    }

    public function test_the_spending_pace_counts_an_event_only_once(): void
    {
        $this->travelTo(CarbonImmutable::today()->startOfMonth()->addDays(9));

        $event = $this->event();
        $this->txn(['amount' => 1000, 'date' => CarbonImmutable::today()->startOfMonth()->addDays(3), 'event_id' => $event->id]);
        $this->user->update(['monthly_income' => 3000]);

        $plan = app(SpendingPlanService::class)->current($this->user->fresh());

        $this->assertSame(1000.0, $plan['spent']);
        $this->assertSame(1000.0, $plan['eventSpent']);
        // dovolenka sa do tempa nepredlžuje — bez ďalších výdavkov ostane tisíc
        $this->assertSame(1000.0, $plan['projectedSpend']);
    }

    public function test_insights_name_the_event_and_compare_routine_spending(): void
    {
        $event = $this->event();
        for ($i = 1; $i <= 3; $i++) {
            $this->txn(['amount' => 200, 'date' => CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($i)->addDay()]);
        }
        $this->txn(['amount' => 200]);
        $this->txn(['amount' => 900, 'event_id' => $event->id]);

        $texts = collect(app(AnalyticsService::class)->insights($this->user, $this->thisMonth()))->pluck('text');

        $this->assertTrue($texts->contains(fn ($t) => str_contains($t, 'Dovolenka Dublin 2026')));
        // bežné míňanie je presne ako priemer — hlásenie „o X % viac" nemá vzniknúť
        $this->assertFalse($texts->contains(fn ($t) => str_contains($t, 'viac než býva priemer')));
    }

    public function test_detail_splits_prepaid_costs_from_days(): void
    {
        $event = $this->event();
        $flight = $this->txn(['amount' => 180, 'date' => CarbonImmutable::parse($event->starts_on)->subDays(40), 'event_id' => $event->id]);
        $this->txn(['amount' => 50, 'date' => $event->starts_on, 'event_id' => $event->id]);
        $this->txn(['amount' => 70, 'date' => $event->starts_on, 'event_id' => $event->id, 'excluded_from_analytics' => true, 'exclusion_reason' => 'Preplatené']);

        $d = app(EventService::class)->detail($event->fresh());

        $this->assertSame(230.0, $d['total']);
        $this->assertSame(2, $d['count']);
        $this->assertSame(1, $d['excluded']['count']);
        $this->assertSame('Vopred', $d['byDay'][0]['label']);
        $this->assertSame(180.0, $d['byDay'][0]['amount']);
        $this->assertSame(50.0, $d['byDay'][1]['amount']);
        $this->assertCount(1 + $event->days(), $d['byDay']);
        $this->assertTrue($d['transactions']->contains('id', $flight->id));
    }

    public function test_event_can_be_created_and_validated(): void
    {
        $this->actingAs($this->user)
            ->post('/events', ['name' => 'Zlé dátumy', 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-01', 'color' => '#22b8cf'])
            ->assertSessionHasErrors('ends_on');

        $this->actingAs($this->user)
            ->post('/events', ['name' => 'Dublin', 'starts_on' => '2026-09-10', 'ends_on' => '2026-09-17', 'color' => '#22b8cf', 'budget' => 1200])
            ->assertRedirect();

        $event = $this->user->events()->first();
        $this->assertSame('Dublin', $event->name);
        $this->assertSame(8, $event->days());
        $this->assertSame('1200.00', $event->budget);
    }

    public function test_bulk_assignment_takes_only_own_expenses(): void
    {
        $event = $this->event();
        $expense = $this->txn();
        $income = $this->txn(['type' => 'income', 'category_id' => $this->salary->id]);

        $other = User::factory()->create();
        $foreignEvent = $other->events()->create(['name' => 'Cudzia', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02', 'color' => '#22b8cf']);

        $this->actingAs($this->user)
            ->patch('/transactions/event', ['ids' => [$expense->id], 'event_id' => $foreignEvent->id])
            ->assertSessionHasErrors('event_id');

        $this->actingAs($this->user)
            ->patch('/transactions/event', ['ids' => [$expense->id, $income->id], 'event_id' => $event->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($event->id, $expense->fresh()->event_id);
        $this->assertNull($income->fresh()->event_id);

        $this->actingAs($this->user)->patch('/transactions/event', ['ids' => [$expense->id], 'event_id' => null]);
        $this->assertNull($expense->fresh()->event_id);
    }

    public function test_sync_adds_and_removes_and_candidates_skip_refunds(): void
    {
        $event = $this->event();
        $a = $this->txn(['event_id' => $event->id]);
        $b = $this->txn();
        $refund = $this->txn(['type' => 'income', 'category_id' => null, 'amount' => 10, 'refund_for_id' => $b->id]);

        $json = $this->actingAs($this->user)->getJson("/events/{$event->id}/candidates")->assertOk()->json('transactions');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($json, 'id'));
        $this->assertNotContains($refund->id, array_column($json, 'id'));

        $this->actingAs($this->user)
            ->put("/events/{$event->id}/transactions", ['add' => [$b->id, $refund->id], 'remove' => [$a->id]])
            ->assertSessionHasNoErrors();

        $this->assertNull($a->fresh()->event_id);
        $this->assertSame($event->id, $b->fresh()->event_id);
        $this->assertNull($refund->fresh()->event_id);
    }

    public function test_deleting_an_event_keeps_its_transactions(): void
    {
        $event = $this->event();
        $t = $this->txn(['event_id' => $event->id]);

        $this->actingAs($this->user)->delete("/events/{$event->id}")->assertRedirect('/events');

        $this->assertNotNull($t->fresh());
        $this->assertNull($t->fresh()->event_id);
    }

    public function test_other_users_cannot_see_an_event(): void
    {
        $event = $this->event();

        $this->actingAs(User::factory()->create())->get("/events/{$event->id}")->assertForbidden();
        $this->actingAs($this->user)->get("/events/{$event->id}")->assertOk();
        $this->actingAs($this->user)->get('/events')->assertOk();
    }

    public function test_transaction_form_keeps_event_only_on_expenses(): void
    {
        $event = $this->event();
        $t = $this->txn(['event_id' => $event->id]);

        $this->actingAs($this->user)->put("/transactions/{$t->id}", [
            'type' => 'income',
            'category_id' => $this->salary->id,
            'event_id' => $event->id,
            'amount' => 100,
            'account_id' => $this->account->id,
            'date' => $t->date->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertNull($t->fresh()->event_id);
    }
}
