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

/**
 * Čísla v Analýzach musia sedieť so zvoleným obdobím a so skupinami kategórií —
 * inak tá istá suma vyzerá na dvoch stránkach inak.
 */
class AnalyticsPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $group;

    protected Category $child;

    protected AnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
        $this->group = Category::create(['user_id' => $this->user->id, 'name' => 'Vozidlo', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'car', 'position' => 1]);
        $this->child = Category::create(['user_id' => $this->user->id, 'parent_id' => $this->group->id, 'name' => 'Pohonné hmoty', 'type' => 'expense', 'color' => '#f0a020', 'icon' => 'fuel', 'position' => 2]);

        $this->analytics = app(AnalyticsService::class);
    }

    /** @param  array<string, mixed>  $attrs */
    protected function txn(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->child->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $attrs));
    }

    protected function month(string $ym): Period
    {
        $d = CarbonImmutable::parse($ym.'-01');

        return new Period('month', $d->startOfMonth(), $d->endOfMonth(), Period::monthLabel($d), $ym);
    }

    public function test_category_breakdown_rolls_children_into_their_group(): void
    {
        $this->txn(['amount' => 60, 'category_id' => $this->child->id]);
        $this->txn(['amount' => 40, 'category_id' => $this->group->id]);

        $rows = $this->analytics->byCategory($this->user, $this->month(CarbonImmutable::today()->format('Y-m')), 'expense');

        $this->assertCount(1, $rows, 'skupina a jej podkategória sa majú zliať do jedného riadku');
        $this->assertSame($this->group->id, $rows[0]['category_id']);
        $this->assertSame(100.0, $rows[0]['amount']);
        $this->assertCount(2, $rows[0]['children']);
    }

    public function test_a_lone_category_has_no_breakdown(): void
    {
        $solo = Category::create(['user_id' => $this->user->id, 'name' => 'Nájom', 'type' => 'expense', 'color' => '#6c5ce7', 'icon' => 'home', 'position' => 3]);
        $this->txn(['amount' => 500, 'category_id' => $solo->id]);

        $rows = $this->analytics->byCategory($this->user, $this->month(CarbonImmutable::today()->format('Y-m')), 'expense');

        $this->assertSame([], $rows[0]['children']);
    }

    public function test_category_detail_only_counts_the_selected_period(): void
    {
        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $this->txn(['amount' => 30, 'date' => $thisMonth->toDateString()]);
        $this->txn(['amount' => 70, 'date' => $thisMonth->subMonthsNoOverflow(1)->toDateString()]);

        $detail = $this->analytics->categoryDetail($this->user, $this->child->id, $this->month($thisMonth->format('Y-m')));

        $this->assertSame(30.0, $detail['total']);
        $this->assertSame(1, $detail['count']);
        $this->assertCount(1, $detail['top']);
    }

    public function test_category_detail_of_a_group_includes_its_children(): void
    {
        $this->txn(['amount' => 25, 'category_id' => $this->child->id]);
        $this->txn(['amount' => 15, 'category_id' => $this->group->id]);

        $detail = $this->analytics->categoryDetail($this->user, $this->group->id, $this->month(CarbonImmutable::today()->format('Y-m')));

        $this->assertTrue($detail['isGroup']);
        $this->assertSame(40.0, $detail['total']);
    }

    public function test_period_report_shows_the_month_before_the_selected_one(): void
    {
        $this->txn(['amount' => 80, 'date' => CarbonImmutable::parse('2026-04-10')->toDateString()]);

        $report = $this->analytics->periodReport($this->user, $this->month('2026-05'));

        $this->assertSame('Mesiac v kocke · Apríl 2026', $report['title']);
        $this->assertSame('Marec 2026', $report['prevLabel']);
        $this->assertSame(80.0, $report['expense']);
    }

    public function test_period_report_of_a_year_shows_the_year_before(): void
    {
        $this->txn(['amount' => 120, 'date' => '2025-06-15']);

        $year = new Period('year', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), '2026', '2026');
        $report = $this->analytics->periodReport($this->user, $year);

        $this->assertSame('Rok v kocke · 2025', $report['title']);
        $this->assertSame(120.0, $report['expense']);
    }

    public function test_period_report_is_null_when_the_target_period_is_empty(): void
    {
        $this->txn(['amount' => 50, 'date' => CarbonImmutable::parse('2026-05-10')->toDateString()]);

        $this->assertNull($this->analytics->periodReport($this->user, $this->month('2026-05')));
    }

    public function test_insights_describe_the_selected_period(): void
    {
        $this->txn(['amount' => 90, 'date' => CarbonImmutable::parse('2026-05-10')->toDateString()]);

        $texts = collect($this->analytics->insights($this->user, $this->month('2026-05')))->pluck('text');

        $this->assertNotEmpty($texts);
        $this->assertTrue($texts->every(fn ($t) => str_starts_with($t, 'Máj 2026 ·')));
    }

    public function test_insights_are_empty_for_a_period_without_transactions(): void
    {
        $this->txn(['amount' => 90, 'date' => CarbonImmutable::parse('2026-05-10')->toDateString()]);

        $this->assertSame([], $this->analytics->insights($this->user, $this->month('2026-06')));
    }

    public function test_budget_on_a_group_counts_its_child_categories(): void
    {
        Budget::create(['user_id' => $this->user->id, 'category_id' => $this->group->id, 'limit_amount' => 500, 'period' => 'month']);

        $start = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->txn(['amount' => 40, 'category_id' => $this->child->id, 'date' => $start]);
        $this->txn(['amount' => 60, 'category_id' => $this->group->id, 'date' => $start]);

        $row = app(FinanceService::class)->budgetProgress($this->user)->first();

        $this->assertSame(100.0, $row['spent']);
        $this->assertTrue($row['is_group']);
    }

    public function test_budget_transactions_match_the_amount_spent(): void
    {
        $budget = Budget::create(['user_id' => $this->user->id, 'category_id' => $this->group->id, 'limit_amount' => 500, 'period' => 'month']);

        $start = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->txn(['amount' => 40, 'category_id' => $this->child->id, 'date' => $start]);
        $this->txn(['amount' => 60, 'category_id' => $this->group->id, 'date' => $start]);
        // Mimo obdobia rozpočtu — do zoznamu nepatrí
        $this->txn(['amount' => 999, 'date' => CarbonImmutable::today()->startOfMonth()->subDay()->toDateString()]);

        $rows = app(FinanceService::class)->budgetTransactions($this->user, $budget);

        $this->assertCount(2, $rows);
        $this->assertSame(100.0, (float) $rows->sum('amount'));
    }

    public function test_the_analytics_page_renders_with_the_selected_period(): void
    {
        $this->txn(['amount' => 10, 'date' => CarbonImmutable::parse('2026-05-10')->toDateString()]);

        $this->actingAs($this->user)
            ->get('/analytics?period=month&ref=2026-05')
            ->assertInertia(fn ($page) => $page
                ->component('gros/Analytics')
                ->where('period.label', 'Máj 2026')
                ->has('expenseByCategory.0.children')
                ->has('periodReport')
                ->has('insights')
            );
    }

    public function test_the_transactions_page_only_lists_the_selected_period(): void
    {
        $this->txn(['amount' => 10, 'date' => CarbonImmutable::parse('2026-05-10')->toDateString()]);
        $this->txn(['amount' => 20, 'date' => CarbonImmutable::parse('2026-04-10')->toDateString()]);

        $this->actingAs($this->user)
            ->get('/transactions?period=month&ref=2026-05')
            ->assertInertia(fn ($page) => $page
                ->component('gros/Transactions')
                ->where('period.label', 'Máj 2026')
                ->has('transactions', 1)
            );
    }
}
