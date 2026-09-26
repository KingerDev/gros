<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AnomalyDetector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Štatistická detekcia nezvyčajných výdavkov. */
class AnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Martin']);
        $this->account = Account::create([
            'user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 500, 'color' => '#4c8dff',
        ]);
    }

    protected function category(string $name, string $type = 'expense', ?Category $parent = null): Category
    {
        return Category::create([
            'user_id' => $this->user->id, 'name' => $name, 'type' => $type,
            'color' => '#e8544e', 'icon' => '💸', 'position' => 1, 'parent_id' => $parent?->id,
        ]);
    }

    protected function spend(Category $c, float $amount, string $date, ?string $note = null): Transaction
    {
        return Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'category_id' => $c->id,
            'type' => 'expense', 'amount' => $amount, 'date' => $date, 'note' => $note,
        ]);
    }

    public function test_an_expense_far_above_the_usual_one_is_flagged(): void
    {
        $food = $this->category('Potraviny');
        for ($i = 1; $i <= 20; $i++) {
            $this->spend($food, 25, CarbonImmutable::today()->subDays($i * 10)->toDateString());
        }
        $big = $this->spend($food, 400, CarbonImmutable::today()->subDays(3)->toDateString(), 'Veľký nákup');

        $found = app(AnomalyDetector::class)->recent($this->user);

        $this->assertCount(1, $found);
        $this->assertSame($big->id, $found[0]['id']);
        $this->assertSame(25.0, $found[0]['usual']);
        $this->assertSame(16.0, $found[0]['times']);
    }

    public function test_a_steadily_expensive_category_is_not_flagged(): void
    {
        // nájom je vysoký, ale nikdy nevyskočí nad svoju bežnú hladinu
        $rent = $this->category('Nájom');
        for ($i = 1; $i <= 12; $i++) {
            $this->spend($rent, 600, CarbonImmutable::today()->subMonthsNoOverflow($i)->toDateString());
        }
        $this->spend($rent, 600, CarbonImmutable::today()->subDays(2)->toDateString());

        $this->assertSame([], app(AnomalyDetector::class)->recent($this->user));
    }

    public function test_old_outliers_are_not_reported_as_recent(): void
    {
        $food = $this->category('Potraviny');
        for ($i = 1; $i <= 12; $i++) {
            $this->spend($food, 20, CarbonImmutable::today()->subMonthsNoOverflow($i)->toDateString());
        }
        $this->spend($food, 500, CarbonImmutable::today()->subMonthsNoOverflow(6)->toDateString(), 'Dávno');

        $this->assertSame([], app(AnomalyDetector::class)->recent($this->user, 45));
    }

    public function test_small_amounts_never_trip_the_alert(): void
    {
        $coffee = $this->category('Káva');
        for ($i = 1; $i <= 15; $i++) {
            $this->spend($coffee, 2, CarbonImmutable::today()->subDays($i * 5)->toDateString());
        }
        // 30 € je pätnásťnásobok, ale stále pod hranicou, ktorá stojí za zmienku
        $this->spend($coffee, 30, CarbonImmutable::today()->subDays(2)->toDateString());

        $this->assertSame([], app(AnomalyDetector::class)->recent($this->user));
    }
}
