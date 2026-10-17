<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EmergencyFundService;
use App\Services\FinancialProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Núdzový fond: nevyhnutné výdavky sa merajú z transakcií, počet mesiacov
 * si volí používateľ.
 */
class EmergencyFundTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    /** @var array<string, Category> */
    protected array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create([
            'user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 0, 'color' => '#4c8dff',
        ]);

        $housing = $this->category('Bývanie');
        $this->category('Nájom', $housing);
        $this->category('Potraviny');
        $this->category('Reštaurácia, fast-food');
        $investments = $this->category('Investície');
        $investments->update(['is_savings' => true]);
        $this->category('Finančné investície', $investments);
    }

    protected function category(string $name, ?Category $parent = null): Category
    {
        return $this->categories[$name] = Category::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => 'expense',
            'color' => '#e8544e',
            'icon' => '💸',
            'position' => count($this->categories) + 1,
            'parent_id' => $parent?->id,
        ]);
    }

    /** Zaúčtuje rovnakú sumu do rovnakej kategórie za posledných N ukončených mesiacov. */
    protected function monthly(string $category, float $amount, int $months = 12, string $type = 'expense'): void
    {
        for ($i = 1; $i <= $months; $i++) {
            Transaction::create([
                'user_id' => $this->user->id,
                'account_id' => $this->account->id,
                'category_id' => $type === 'expense' ? $this->categories[$category]->id : null,
                'type' => $type,
                'amount' => $amount,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(5)->toDateString(),
            ]);
        }
    }

    /** Jedna transakcia N ukončených mesiacov dozadu. */
    protected function transaction(Category $category, float $amount, int $monthsAgo): Transaction
    {
        return Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => $amount,
            'date' => CarbonImmutable::today()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(5)->toDateString(),
        ]);
    }

    public function test_only_essential_categories_count_towards_the_target(): void
    {
        $this->monthly('Nájom', 600);
        $this->monthly('Potraviny', 300);
        $this->monthly('Reštaurácia, fast-food', 200);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user, $fund->profile($this->user));

        $this->assertSame(900.0, $expenses['essential']);
        $this->assertSame(1_100.0, $expenses['total']);
        $this->assertSame(200.0, $expenses['discretionary']);
    }

    public function test_money_sent_to_investments_is_not_treated_as_spending(): void
    {
        $this->monthly('Nájom', 600);
        $this->monthly('Finančné investície', 400);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user, $fund->profile($this->user));

        // investovaných 400 € nie je spotreba — v kríze sa proste zastaví
        $this->assertSame(600.0, $expenses['total']);
        $this->assertSame(400.0, $expenses['savings_excluded']);
    }

    public function test_a_custom_category_selection_overrides_the_defaults(): void
    {
        $this->monthly('Nájom', 600);
        $this->monthly('Reštaurácia, fast-food', 200);

        $this->user->update(['reserve_profile' => [
            'essential_category_ids' => [$this->categories['Reštaurácia, fast-food']->id],
        ]]);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user->fresh(), $fund->profile($this->user->fresh()));

        $this->assertSame(200.0, $expenses['essential']);
    }

    public function test_the_target_is_essential_spending_times_the_chosen_months(): void
    {
        $this->monthly('Nájom', 1_000);

        $default = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $this->assertSame(3.0, $default['months']);
        $this->assertSame(3_000.0, $default['target']);

        $this->user->update(['reserve_profile' => ['months' => 6]]);
        $six = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $this->assertSame(6_000.0, $six['target']);
    }

    public function test_an_older_months_override_is_still_honoured(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->user->update(['reserve_profile' => ['months_override' => 8]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->assertSame(8.0, $report['months']);
        $this->assertSame(8_000.0, $report['target']);
    }

    public function test_milestones_progress_with_the_balance(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->account->update(['balance' => 3_500]);
        $this->user->update(['reserve_profile' => ['months' => 4, 'source' => 'all_cash']]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $milestones = collect($report['milestones'])->keyBy('key');
        $this->assertTrue($milestones['buffer']['reached']);   // 1 000 €
        $this->assertFalse($milestones['target']['reached']);  // 4 mes. = 4 000 €
        $this->assertSame(500.0, $milestones['target']['missing']);
        $this->assertSame(3.5, $report['covered_months']);
    }

    public function test_by_default_this_months_spending_is_not_counted_as_reserve(): void
    {
        $this->monthly('Nájom', 600);
        $this->monthly('Reštaurácia, fast-food', 200);
        $this->account->update(['balance' => 5_000]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        // 5 000 € na účte mínus 800 € bežných výdavkov tohto mesiaca
        $this->assertSame('cash_minus_month', $report['profile']['source']);
        $this->assertSame(4_200.0, $report['held']);
    }

    public function test_the_reserve_can_be_tied_to_a_single_account(): void
    {
        $this->monthly('Nájom', 500);
        $this->account->update(['balance' => 5_000]);
        $savings = Account::create([
            'user_id' => $this->user->id, 'name' => 'Rezerva', 'type' => 'cash', 'balance' => 1_200, 'color' => '#2ba35a',
        ]);

        $this->user->update(['reserve_profile' => ['source' => 'account', 'account_id' => $savings->id]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->assertSame(1_200.0, $report['held']);
    }

    // ── Splátky úverov ──────────────────────────────────────────────────

    public function test_loan_payments_count_even_without_a_single_transaction(): void
    {
        $this->monthly('Nájom', 500);
        Loan::create([
            'user_id' => $this->user->id, 'kind' => 'owe', 'name' => 'Auto', 'balance' => 17_600,
            'principal' => 20_000, 'payment' => 400, 'rate' => 0,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user->fresh(), $fund->profile($this->user));

        // 500 € nájom + 400 € splátka, hoci splátka nie je ani raz zaúčtovaná
        $this->assertSame(900.0, $expenses['essential']);
        $this->assertSame(400.0, $expenses['loan_payments']);
        $this->assertSame('Auto', $expenses['loans'][0]['name']);
    }

    public function test_posted_loan_payments_are_not_counted_twice(): void
    {
        $this->monthly('Nájom', 500);
        Loan::create([
            'user_id' => $this->user->id, 'kind' => 'owe', 'name' => 'Auto', 'balance' => 17_600,
            'principal' => 20_000, 'payment' => 400, 'rate' => 0,
            'next_payment' => CarbonImmutable::today()->addMonth()->toDateString(), 'color' => '#4c8dff',
        ]);

        // tie isté splátky navyše zaúčtované ako transakcie zo zdroja "loan"
        for ($i = 1; $i <= 12; $i++) {
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $this->account->id,
                'category_id' => $this->categories['Nájom']->id, 'type' => 'expense', 'amount' => 400,
                'source' => 'loan',
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(2)->toDateString(),
            ]);
        }

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user->fresh(), $fund->profile($this->user));

        $this->assertSame(900.0, $expenses['essential']);
    }

    // ── Jednorazové výdavky ─────────────────────────────────────────────

    public function test_a_one_off_spike_is_kept_out_of_the_average(): void
    {
        // bežné návštevy 20 € mesačne, k tomu rovnátka na tri splátky
        $medical = $this->category('Zdravotná starostlivosť, lekár');
        for ($i = 1; $i <= 12; $i++) {
            $this->transaction($medical, 20, $i);
        }
        foreach ([[2, 2_050], [5, 1_850], [8, 600]] as [$monthsAgo, $amount]) {
            $this->transaction($medical, $amount, $monthsAgo);
        }

        $this->user->update(['reserve_profile' => ['essential_category_ids' => [$medical->id]]]);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user->fresh(), $fund->profile($this->user->fresh()));

        // ostane len 20 €/mes., nie (240 + 4 500) / 12 = 395 €
        $this->assertSame(20.0, $expenses['essential']);
        $this->assertCount(3, $expenses['one_offs']);
        $this->assertSame(2_050.0, $expenses['one_offs'][0]['amount']);
        $this->assertEqualsWithDelta(375.0, $expenses['one_off_monthly'], 0.5);
    }

    public function test_steady_spending_is_never_flagged_as_a_one_off(): void
    {
        // stabilný nájom 600 € je vysoký, ale nikdy nevyskočí nad svoj bežný mesiac
        $this->monthly('Nájom', 600);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user, $fund->profile($this->user));

        $this->assertSame([], $expenses['one_offs']);
        $this->assertSame(600.0, $expenses['essential']);
    }

    public function test_a_one_off_marked_as_recurring_counts_again(): void
    {
        $medical = $this->category('Zdravotná starostlivosť, lekár');
        for ($i = 1; $i <= 12; $i++) {
            $this->transaction($medical, 20, $i);
        }
        $spike = $this->transaction($medical, 1_200, 3);

        $this->user->update(['reserve_profile' => [
            'essential_category_ids' => [$medical->id],
            'recurring_transaction_ids' => [$spike->id],
        ]]);

        $fund = app(EmergencyFundService::class);
        $expenses = $fund->essentialExpenses($this->user->fresh(), $fund->profile($this->user->fresh()));

        // 240 € bežných + 1 200 € rozložených na 12 mesiacov
        $this->assertSame(120.0, $expenses['essential']);
        $this->assertTrue($expenses['one_offs'][0]['treat_as_recurring']);
    }

    // ── Miera úspor meria spotrebu, nie hrubý rozdiel ───────────────────

    public function test_money_moved_to_the_portfolio_does_not_lower_the_savings_rate(): void
    {
        // zarába 2 000, minie 500 na život a 500 pošle do portfólia
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 500);
        $this->monthly('Finančné investície', 500);

        $report = app(FinancialProfileService::class)->savingsRateReport($this->user->fresh(), 5.0, 4.0);
        $window = $report['windows'][12];

        // hrubý rozdiel by tvrdil 50 %, hoci odložil 75 % príjmu
        $this->assertSame(50.0, $window['gross_rate']);
        $this->assertSame(75.0, $window['rate']);
        $this->assertSame(6_000.0, $window['savings_flow']);
    }

    /** Rovnaká suma do kategórie každý ukončený mesiac. */
    protected function transactionEachMonth(Category $category, float $amount, int $months): void
    {
        for ($i = 1; $i <= $months; $i++) {
            $this->transaction($category, $amount, $i);
        }
    }

    public function test_a_one_off_expense_still_counts_against_the_savings_rate(): void
    {
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 500);
        // rovnátka na dve splátky — jednorazové, ale peniaze sú preč
        $medical = $this->category('Zdravotná starostlivosť, lekár');
        $this->transaction($medical, 3_000, 4);
        $this->transaction($medical, 3_000, 7);

        $report = app(FinancialProfileService::class)->savingsRateReport($this->user->fresh(), 5.0, 4.0);
        $window = $report['windows'][12];

        // (24 000 − 6 000 − 6 000) / 24 000
        $this->assertSame(50.0, $window['rate']);
        $this->assertSame(50.0, $window['gross_rate']);
    }

    public function test_the_profile_separates_consumption_from_money_sent_to_the_portfolio(): void
    {
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 500);
        $this->monthly('Finančné investície', 400);

        $profile = app(FinancialProfileService::class)->forUser($this->user->fresh());
        $m = $profile['measured'];

        $this->assertSame(900.0, $m['expense']);       // hrubé výdavky
        $this->assertSame(500.0, $m['consumption']);   // bez portfólia
        $this->assertSame(1_500.0, $m['savings']);
        $this->assertSame(75.0, $m['savings_rate']);
        $this->assertSame(400.0, $m['savings_flow']);
    }

    public function test_borrowed_money_is_not_income(): void
    {
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 1_000);
        $loanIn = Category::create([
            'user_id' => $this->user->id, 'name' => 'Pôžičky, prenájom', 'type' => 'income', 'is_savings' => true,
            'color' => '#fab005', 'icon' => '📥', 'position' => 99,
        ]);
        Transaction::create([
            'user_id' => $this->user->id, 'account_id' => $this->account->id, 'category_id' => $loanIn->id,
            'type' => 'income', 'amount' => 12_000,
            'date' => CarbonImmutable::today()->subMonthsNoOverflow(2)->startOfMonth()->addDays(3)->toDateString(),
        ]);

        $m = app(FinancialProfileService::class)->forUser($this->user->fresh())['measured'];

        $this->assertSame(2_000.0, $m['income']);
        $this->assertSame(50.0, $m['savings_rate']);
    }

    public function test_the_page_renders(): void
    {
        $this->monthly('Nájom', 800);

        $this->actingAs($this->user)->get('/reserve')->assertOk();
    }

    public function test_the_profile_is_saved_and_foreign_ids_are_rejected(): void
    {
        $stranger = User::factory()->create();
        $foreign = Account::create(['user_id' => $stranger->id, 'name' => 'Cudzí', 'type' => 'cash', 'balance' => 999, 'color' => '#000000']);

        $this->actingAs($this->user)->post('/reserve', [
            'months' => 6,
            'source' => 'account',
            'account_id' => $foreign->id,
            'essential_category_ids' => [$this->categories['Potraviny']->id],
        ])->assertRedirect();

        $profile = $this->user->fresh()->reserve_profile;
        $this->assertSame(6, $profile['months']);
        // cudzí účet sa nesmie uložiť
        $this->assertNull($profile['account_id']);
        $this->assertSame([$this->categories['Potraviny']->id], $profile['essential_category_ids']);
    }

    public function test_the_dashboard_shows_the_same_reserve_as_the_reserve_page(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->account->update(['balance' => 4_000]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reserve.months', fn ($v) => (float) $v === $report['covered_months'])
                ->where('reserve.gap', fn ($v) => (float) $v === $report['gap'])
            );
    }

    public function test_money_saved_for_goals_is_not_counted_as_reserve(): void
    {
        $this->monthly('Nájom', 500);
        $this->account->update(['balance' => 3_000]);
        $this->user->goals()->create(['name' => 'Dovolenka', 'target_amount' => 1_500, 'saved_amount' => 800, 'color' => '#4c8dff']);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        // 3 000 − 800 na cieľ − 500 bežných výdavkov tohto mesiaca
        $this->assertSame(1_700.0, $report['held']);
        $this->assertSame(800.0, $report['held_basis']['goals']);
    }
}
