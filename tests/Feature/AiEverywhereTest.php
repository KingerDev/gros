<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\MonthlyBriefing;
use App\Services\AnomalyDetector;
use App\Services\CategorySuggester;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI mimo chatu: mesačný komentár, návrh kategórie a štatistická detekcia
 * nezvyčajných výdavkov (tá zámerne bez modelu).
 */
class AiEverywhereTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => 'test-key']);

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

    protected function reply(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    // ── Nezvyčajné výdavky (bez AI) ─────────────────────────────────────

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

    // ── Mesačný komentár ────────────────────────────────────────────────

    public function test_the_briefing_is_generated_and_cached(): void
    {
        $this->spend($this->category('Nákupy'), 200, CarbonImmutable::today()->toDateString());

        Http::fake(['*' => Http::response($this->reply('Tento mesiac ti vyskočili Nákupy o 200 €.'))]);

        $svc = app(MonthlyBriefing::class);
        $first = $svc->forUser($this->user);
        $second = $svc->forUser($this->user);

        $this->assertTrue($first['ok']);
        $this->assertSame($first['text'], $second['text']);
        // druhé volanie sa už modelu nepýta
        Http::assertSentCount(1);
    }

    public function test_the_briefing_is_skipped_without_data_or_key(): void
    {
        Http::fake();

        $this->assertSame('no_data', app(MonthlyBriefing::class)->forUser($this->user)['reason']);

        config(['services.openai.key' => null]);
        $this->assertSame('not_configured', app(MonthlyBriefing::class)->forUser($this->user)['reason']);

        Http::assertNothingSent();
    }

    public function test_a_failing_model_does_not_break_the_dashboard(): void
    {
        $this->spend($this->category('Nákupy'), 200, CarbonImmutable::today()->toDateString());
        Http::fake(['*' => Http::response([], 500)]);

        $this->assertFalse(app(MonthlyBriefing::class)->forUser($this->user)['ok']);

        $this->actingAs($this->user)->get('/dashboard')->assertOk();
    }

    // ── Návrh kategórie ─────────────────────────────────────────────────

    public function test_history_beats_the_model(): void
    {
        $group = $this->category('Jedlo a pitie');
        $groceries = $this->category('Potraviny', 'expense', $group);
        $this->spend($groceries, 30, CarbonImmutable::today()->subDays(5)->toDateString(), 'Lidl');

        Http::fake(['*' => Http::response($this->reply('{"category_id": 999}'))]);

        $id = app(CategorySuggester::class)->suggest($this->user, 'Lidl', 'expense');

        $this->assertSame($groceries->id, $id);
        Http::assertNothingSent();
    }

    public function test_the_model_fills_in_what_history_cannot(): void
    {
        $group = $this->category('Jedlo a pitie');
        $groceries = $this->category('Potraviny', 'expense', $group);

        Http::fake(['*' => Http::response($this->reply('{"category_id": '.$groceries->id.'}'))]);

        $id = app(CategorySuggester::class)->suggest($this->user, 'Kaufland Prešov', 'expense');

        $this->assertSame($groceries->id, $id);
    }

    public function test_a_category_the_model_invented_is_rejected(): void
    {
        $group = $this->category('Jedlo a pitie');
        $this->category('Potraviny', 'expense', $group);

        Http::fake(['*' => Http::response($this->reply('{"category_id": 424242}'))]);

        $this->assertNull(app(CategorySuggester::class)->suggest($this->user, 'Niečo neznáme', 'expense'));
    }

    public function test_the_same_note_is_only_asked_once(): void
    {
        $group = $this->category('Jedlo a pitie');
        $groceries = $this->category('Potraviny', 'expense', $group);

        Http::fake(['*' => Http::response($this->reply('{"category_id": '.$groceries->id.'}'))]);

        $svc = app(CategorySuggester::class);
        $svc->suggest($this->user, 'Kaufland Prešov', 'expense');
        $svc->suggest($this->user, 'Kaufland Prešov', 'expense');

        Http::assertSentCount(1);
    }

    // ── Kontextové otázky ───────────────────────────────────────────────

    public function test_a_question_from_another_page_is_prefilled(): void
    {
        $this->actingAs($this->user)
            ->get('/assistant?q='.urlencode('Prečo som minul viac?'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('prefill', 'Prečo som minul viac?'));
    }

    public function test_the_briefing_endpoint_responds(): void
    {
        Http::fake(['*' => Http::response($this->reply('Všetko v pohode.'))]);
        $this->spend($this->category('Nákupy'), 100, CarbonImmutable::today()->toDateString());

        $this->actingAs($this->user)->getJson('/assistant-briefing')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
