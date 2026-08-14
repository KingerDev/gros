<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Splátka lízingu je záväzok od prvého mesiaca — graf ju nesmie ukázať
 * ako voľný výdavok len preto, že ešte nemá trojmesačnú históriu.
 */
class FixedVsVariableTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create([
            'user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1_000, 'color' => '#4c8dff',
        ]);
        $this->category = Category::create([
            'user_id' => $this->user->id, 'name' => 'Lízing', 'type' => 'expense',
            'color' => '#9c36b5', 'icon' => '💳', 'position' => 1,
        ]);
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

    /** @return array<string, mixed> */
    protected function currentMonth(): array
    {
        $ym = CarbonImmutable::today()->format('Y-m');
        $series = app(AnalyticsService::class)->fixedVsVariable($this->user, 12)['series'];

        return collect($series)->firstWhere('ym', $ym);
    }

    public function test_a_single_loan_payment_counts_as_fixed(): void
    {
        $this->txn(['amount' => 400, 'note' => 'Auto', 'source' => 'loan', 'source_id' => 1]);

        $month = $this->currentMonth();

        $this->assertSame(400.0, $month['fixed']);
        $this->assertSame(0.0, $month['variable']);
    }

    public function test_a_single_subscription_payment_counts_as_fixed(): void
    {
        $this->txn(['amount' => 19.99, 'note' => 'Netflix', 'source' => 'subscription', 'source_id' => 1]);

        $this->assertSame(19.99, $this->currentMonth()['fixed']);
    }

    public function test_a_one_off_manual_expense_stays_variable(): void
    {
        $this->txn(['amount' => 400, 'note' => 'Auto']);

        $month = $this->currentMonth();

        $this->assertSame(0.0, $month['fixed']);
        $this->assertSame(400.0, $month['variable']);
    }

    public function test_a_manual_expense_repeated_in_three_months_becomes_fixed(): void
    {
        $today = CarbonImmutable::today()->startOfMonth();
        foreach ([2, 1, 0] as $back) {
            $this->txn(['amount' => 550, 'note' => 'Nájom', 'date' => $today->subMonths($back)->toDateString()]);
        }

        $this->assertSame(550.0, $this->currentMonth()['fixed']);
    }

    public function test_a_refunded_loan_payment_only_counts_the_net(): void
    {
        // refunded_amount nie je fillable — udržiava ho tok vrátení, nie zápis
        $payment = $this->txn(['amount' => 400, 'note' => 'Auto', 'source' => 'loan', 'source_id' => 1]);
        $payment->forceFill(['refunded_amount' => 150])->save();

        $this->assertSame(250.0, $this->currentMonth()['fixed']);
    }
}
