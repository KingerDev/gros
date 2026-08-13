<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillLeasing;
use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Príkaz zapisuje do skutočných finančných záznamov, takže musí platiť:
 * bez --apply nezapíše nič, doplnenie nerozhodí zostatok a dá sa vrátiť.
 */
class BackfillLeasingTest extends TestCase
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
        Loan::create([
            'user_id' => $this->user->id, 'kind' => 'owe', 'name' => 'Auto',
            'balance' => 17_600, 'principal' => 19_200, 'payment' => 400, 'rate' => 0,
            'account_id' => $this->account->id, 'category_id' => $this->category->id,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('gros:backfill-leasing', ['--from' => '2026-05', '--to' => '2026-07'])
            ->assertSuccessful();

        $this->assertSame(0, Transaction::count());
        $this->assertSame('1000.00', $this->account->fresh()->balance);
    }

    public function test_backfilling_keeps_the_balance_untouched(): void
    {
        $this->artisan('gros:backfill-leasing', ['--from' => '2026-05', '--to' => '2026-07', '--apply' => true])
            ->assertSuccessful();

        // tri mesiace × (príjem 400 + výdavok 400)
        $this->assertSame(6, Transaction::count());
        $this->assertSame(3, Transaction::where('type', 'income')->count());
        $this->assertSame(3, Transaction::where('type', 'expense')->count());

        // obe strany sa vyrušia, zostatok sa nesmie pohnúť
        $this->assertSame('1000.00', $this->account->fresh()->balance);
    }

    public function test_a_month_with_a_posted_payment_only_gets_the_income(): void
    {
        // splátku už zaúčtovala automatika
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'category_id' => $this->category->id,
            'type' => 'expense', 'amount' => 400, 'date' => '2026-06-01', 'source' => 'loan',
        ]);

        $this->artisan('gros:backfill-leasing', ['--from' => '2026-06', '--to' => '2026-06', '--apply' => true])
            ->assertSuccessful();

        $added = Transaction::where('note', 'like', BackfillLeasing::TAG.'%')->get();
        $this->assertCount(1, $added);
        $this->assertSame('income', $added->first()->type);

        // chýbal len príjem, takže zostatok stúpne o 400
        $this->assertSame('1400.00', $this->account->fresh()->balance);
    }

    public function test_everything_can_be_reverted(): void
    {
        $this->artisan('gros:backfill-leasing', ['--from' => '2026-05', '--to' => '2026-08', '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(8, Transaction::where('note', 'like', BackfillLeasing::TAG.'%')->count());

        $this->artisan('gros:backfill-leasing', ['--revert' => true])->assertSuccessful();

        $this->assertSame(0, Transaction::where('note', 'like', BackfillLeasing::TAG.'%')->count());
        $this->assertSame('1000.00', $this->account->fresh()->balance);
    }

    public function test_a_user_without_a_loan_is_refused(): void
    {
        $stranger = User::factory()->create();

        $this->artisan('gros:backfill-leasing', ['--user' => $stranger->id, '--apply' => true])
            ->assertFailed();

        $this->assertSame(0, Transaction::count());
    }
}
