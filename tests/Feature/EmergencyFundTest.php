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
 * Núdzový fond: odporúčanie musí vychádzať z nameraných dát a hýbať sa,
 * keď sa hýbu príjmy a výdavky.
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

    public function test_risk_factors_add_up_into_the_recommended_months(): void
    {
        $fund = app(EmergencyFundService::class);
        $steady = ['months' => 12, 'average' => 2_000.0, 'volatility' => 5.0, 'is_volatile' => false, 'worst' => 1_900.0];
        $expenses = ['essential_share' => 50.0];

        // stabilný zamestnanec, sám, s nárokom na dávku → 3 + 0 + 0 − 1 = 2, orezané na 3
        $employee = $fund->recommendMonths(
            ['income_type' => 'stable', 'household' => 'single', 'unemployment_benefit' => true, 'health_risk' => false, 'months_override' => null],
            $expenses,
            $steady
        );
        $this->assertSame(3, $employee['recommended']);
        $this->assertTrue($employee['clamped']);

        // SZČO so závislými osobami a bez dávky → 3 + 3 + 2 + 1 = 9
        $freelancer = $fund->recommendMonths(
            ['income_type' => 'self_employed', 'household' => 'dependents', 'unemployment_benefit' => false, 'health_risk' => false, 'months_override' => null],
            $expenses,
            $steady
        );
        $this->assertSame(9, $freelancer['recommended']);
    }

    public function test_measured_income_volatility_raises_the_recommendation(): void
    {
        $fund = app(EmergencyFundService::class);
        $profile = ['income_type' => 'stable', 'household' => 'dual_income', 'unemployment_benefit' => false, 'health_risk' => false, 'months_override' => null];
        $expenses = ['essential_share' => 50.0];

        $steady = $fund->recommendMonths($profile, $expenses, ['is_volatile' => false, 'volatility' => 4.0]);
        $jumpy = $fund->recommendMonths($profile, $expenses, ['is_volatile' => true, 'volatility' => 60.0]);

        $this->assertSame($steady['raw'] + 1, $jumpy['raw']);
    }

    public function test_volatility_is_measured_from_real_income(): void
    {
        // striedavo 500 a 2 500 € — silne kolísavý príjem
        for ($i = 1; $i <= 12; $i++) {
            Transaction::create([
                'user_id' => $this->user->id, 'account_id' => $this->account->id, 'type' => 'income',
                'amount' => $i % 2 === 0 ? 2_500 : 500,
                'date' => CarbonImmutable::today()->subMonthsNoOverflow($i)->startOfMonth()->addDays(5)->toDateString(),
            ]);
        }

        $income = app(EmergencyFundService::class)->incomeStats($this->user);

        $this->assertSame(1_500.0, $income['average']);
        $this->assertTrue($income['is_volatile']);
        $this->assertGreaterThan(50, $income['volatility']);
    }

    public function test_a_months_override_wins_over_the_recommendation(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->user->update(['reserve_profile' => ['months_override' => 8]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->assertSame(8.0, $report['months']['months']);
        $this->assertTrue($report['months']['overridden']);
        $this->assertSame(8_000.0, $report['target']);
    }

    public function test_milestones_progress_with_the_balance(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->account->update(['balance' => 3_500]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $milestones = collect($report['milestones'])->keyBy('key');
        $this->assertTrue($milestones['buffer']['reached']);   // 1 000 €
        $this->assertTrue($milestones['base']['reached']);     // 3 000 €
        $this->assertFalse($milestones['target']['reached']);  // 4 mes. = 4 000 €
        $this->assertSame(500.0, $milestones['target']['missing']);
        $this->assertSame(3.5, $report['covered_months']);
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

    public function test_the_fill_plan_splits_the_monthly_surplus(): void
    {
        $this->monthly('Nájom', 1_000);
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->account->update(['balance' => 0]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->assertGreaterThan(0, $report['gap']);
        $this->assertSame(1_000.0, $report['plan']['monthly_surplus']);

        $half = collect($report['plan']['options'])->firstWhere('share', 50);
        $this->assertSame(500.0, $half['monthly']);
        $this->assertSame(500.0, $half['investing']);
        $this->assertSame((int) ceil($report['gap'] / 500), $half['months']);
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

    public function test_a_student_is_not_penalised_for_missing_unemployment_insurance(): void
    {
        $fund = app(EmergencyFundService::class);
        $steady = ['is_volatile' => false, 'volatility' => 5.0];
        $expenses = ['essential_share' => 50.0];

        $student = $fund->recommendMonths(
            ['income_type' => 'student', 'household' => 'single', 'unemployment_benefit' => false, 'health_risk' => false, 'months_override' => null],
            $expenses,
            $steady
        );

        // model „N mesiacov bez práce" sem nesedí — ostáva pri základe
        $this->assertSame(3, $student['recommended']);
        $benefit = collect($student['factors'])->firstWhere('key', 'benefit');
        $this->assertSame(0, $benefit['delta']);
        $this->assertStringContainsString('dohode o brigádnickej práci', $benefit['note']);
    }

    public function test_students_get_a_graduation_transition_milestone(): void
    {
        $this->monthly('Nájom', 300);
        $this->monthly('Reštaurácia, fast-food', 200);

        $this->user->update(['reserve_profile' => [
            'income_type' => 'student',
            'graduation_year' => 2029,
            'post_graduation_expenses' => 1_200,
        ]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $graduation = collect($report['milestones'])->firstWhere('key', 'graduation');

        $this->assertNotNull($graduation);
        $this->assertSame('Prechod po škole (2029)', $graduation['label']);
        $this->assertSame(3_600.0, $graduation['amount']); // 3 × 1 200 €
        $this->assertFalse($graduation['is_estimate']);
    }

    public function test_the_graduation_milestone_falls_back_to_current_spending(): void
    {
        $this->monthly('Nájom', 300);
        $this->monthly('Reštaurácia, fast-food', 200);

        $this->user->update(['reserve_profile' => ['income_type' => 'student']]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $graduation = collect($report['milestones'])->firstWhere('key', 'graduation');

        // bez vlastného odhadu sa berú dnešné celkové výdavky (500 €)
        $this->assertSame(500.0, $graduation['monthly_basis']);
        $this->assertSame(1_500.0, $graduation['amount']);
        $this->assertTrue($graduation['is_estimate']);
    }

    public function test_non_students_have_no_graduation_milestone(): void
    {
        $this->monthly('Nájom', 500);
        $this->user->update(['reserve_profile' => ['income_type' => 'stable']]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());

        $this->assertNull(collect($report['milestones'])->firstWhere('key', 'graduation'));
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

    public function test_a_one_off_expense_does_not_sink_the_savings_rate(): void
    {
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 500);
        // rovnátka na dve splátky
        $medical = $this->category('Zdravotná starostlivosť, lekár');
        $this->transaction($medical, 3_000, 4);
        $this->transaction($medical, 3_000, 7);

        $report = app(FinancialProfileService::class)->savingsRateReport($this->user->fresh(), 5.0, 4.0);
        $window = $report['windows'][12];

        $this->assertSame(6_000.0, $window['one_off']);
        $this->assertSame(75.0, $window['rate']);
        $this->assertSame(50.0, $window['gross_rate']);
    }

    public function test_the_profile_reports_both_the_gross_and_the_recurring_figure(): void
    {
        $this->monthly('mzda', 2_000, 12, 'income');
        $this->monthly('Nájom', 500);
        $this->monthly('Finančné investície', 400);

        $profile = app(FinancialProfileService::class)->forUser($this->user->fresh());
        $m = $profile['measured'];

        $this->assertSame(900.0, $m['expense']);            // hrubé výdavky
        $this->assertSame(500.0, $m['recurring_expense']);  // po očistení
        $this->assertSame(1_100.0, $m['savings']);
        $this->assertSame(1_500.0, $m['recurring_savings']);
        $this->assertSame(400.0, $m['savings_flow']);
    }

    // ── Život po škole ──────────────────────────────────────────────────

    public function test_the_after_school_estimate_adds_rent_to_current_non_housing_costs(): void
    {
        // dnes: 300 na jedlo, 100 na internát — bývanie sa pri odhade nahradí nájmom
        $this->monthly('Potraviny', 300);
        $housing = $this->categories['Bývanie'];
        $this->transactionEachMonth($this->categories['Nájom'], 100, 12);

        $this->user->update(['reserve_profile' => [
            'income_type' => 'student',
            'graduation_year' => 2029,
            'after_school_city' => 'kosice',
            'after_school_share' => 1.0,
        ]]);

        $fund = app(EmergencyFundService::class);
        $user = $this->user->fresh();
        $profile = $fund->profile($user);
        $a = $fund->afterSchoolEstimate($user, $profile, $fund->essentialExpenses($user, $profile));

        $this->assertTrue($a['available']);
        $this->assertSame('Košice', $a['basis']);
        $this->assertSame(736.0, $a['rent']);
        // súčasné bývanie sa odráta, aby sa nepočítalo dvakrát
        $this->assertSame(100.0, $a['housing_now']);
        $this->assertSame(300.0, $a['current_without_housing']);
        $this->assertSame(1_036.0, $a['estimate']);

        $this->assertNotNull($housing);
    }

    public function test_a_shared_flat_halves_the_rent_assumption(): void
    {
        $this->monthly('Potraviny', 400);
        $this->user->update(['reserve_profile' => [
            'income_type' => 'student', 'after_school_city' => 'bratislava', 'after_school_share' => 0.5,
        ]]);

        $fund = app(EmergencyFundService::class);
        $user = $this->user->fresh();
        $profile = $fund->profile($user);
        $a = $fund->afterSchoolEstimate($user, $profile, $fund->essentialExpenses($user, $profile));

        $this->assertSame(948.0, $a['rent_full']);
        $this->assertSame(474.0, $a['rent']);
        $this->assertSame(874.0, $a['estimate']);
    }

    public function test_the_graduation_milestone_uses_the_rent_based_estimate(): void
    {
        $this->monthly('Potraviny', 400);
        $this->user->update(['reserve_profile' => [
            'income_type' => 'student', 'graduation_year' => 2029, 'after_school_size' => '1', 'after_school_share' => 1.0,
        ]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $graduation = collect($report['milestones'])->firstWhere('key', 'graduation');

        // 400 € dnešné výdavky + 468 € za 1-izbový byt, krát tri mesiace
        $this->assertSame(868.0, $graduation['monthly_basis']);
        $this->assertSame(2_604.0, $graduation['amount']);
        $this->assertTrue($graduation['from_rent_index']);
    }

    public function test_a_custom_estimate_still_beats_the_reference_data(): void
    {
        $this->monthly('Potraviny', 400);
        $this->user->update(['reserve_profile' => [
            'income_type' => 'student', 'after_school_city' => 'bratislava', 'post_graduation_expenses' => 1_800,
        ]]);

        $report = app(EmergencyFundService::class)->forUser($this->user->fresh());
        $graduation = collect($report['milestones'])->firstWhere('key', 'graduation');

        $this->assertSame(1_800.0, $graduation['monthly_basis']);
        $this->assertFalse($graduation['from_rent_index']);
    }

    /** Rovnaká suma do kategórie každý ukončený mesiac. */
    protected function transactionEachMonth(Category $category, float $amount, int $months): void
    {
        for ($i = 1; $i <= $months; $i++) {
            $this->transaction($category, $amount, $i);
        }
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
            'income_type' => 'self_employed',
            'household' => 'dependents',
            'unemployment_benefit' => false,
            'health_risk' => true,
            'source' => 'account',
            'account_id' => $foreign->id,
            'months_override' => null,
            'essential_category_ids' => [$this->categories['Potraviny']->id],
        ])->assertRedirect();

        $profile = $this->user->fresh()->reserve_profile;
        $this->assertSame('self_employed', $profile['income_type']);
        // cudzí účet sa nesmie uložiť
        $this->assertNull($profile['account_id']);
        $this->assertSame([$this->categories['Potraviny']->id], $profile['essential_category_ids']);
    }
}
