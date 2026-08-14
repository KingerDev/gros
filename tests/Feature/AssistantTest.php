<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Chat;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Ai\FinanceToolkit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asistent: nástroje musia vracať skutočné dáta a slučka s volaniami
 * nástrojov musí prebehnúť a uložiť sa celá.
 */
class AssistantTest extends TestCase
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
            'user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1_000, 'color' => '#4c8dff',
        ]);
    }

    protected function category(string $name, ?Category $parent = null): Category
    {
        return Category::create([
            'user_id' => $this->user->id, 'name' => $name, 'type' => 'expense',
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

    /** Odpoveď modelu bez volania nástrojov. */
    protected function reply(string $text): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
    }

    /** Odpoveď modelu, ktorá si pýta nástroj. */
    protected function toolCall(string $name, array $args): array
    {
        return ['choices' => [['message' => [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($args)],
            ]],
        ]]]];
    }

    // ── Nástroje ────────────────────────────────────────────────────────

    public function test_compare_periods_finds_the_category_that_grew(): void
    {
        $food = $this->category('Jedlo a pitie');
        $restaurant = $this->category('Reštaurácia', $food);
        $shopping = $this->category('Nákupy');

        $this->spend($restaurant, 400, '2026-07-05');
        $this->spend($shopping, 100, '2026-07-06');
        $this->spend($restaurant, 150, '2026-06-05');
        $this->spend($shopping, 100, '2026-06-06');

        $r = app(FinanceToolkit::class)->call($this->user, 'compare_periods', [
            'a_from' => '2026-07-01', 'a_to' => '2026-07-31',
            'b_from' => '2026-06-01', 'b_to' => '2026-06-30',
        ]);

        $this->assertSame(500.0, $r['vydavky_a']);
        $this->assertSame(250.0, $r['vydavky_b']);
        $this->assertSame(250.0, $r['rozdiel_spolu']);

        // najväčšia zmena je jedlo, zrolované pod skupinu; nezmenené nákupy vypadnú
        $top = $r['zmeny_podla_kategorie'][0];
        $this->assertSame('Jedlo a pitie', $top['kategoria']);
        $this->assertSame(250.0, $top['rozdiel']);
        $this->assertCount(1, $r['zmeny_podla_kategorie']);
    }

    public function test_transactions_can_be_narrowed_by_category_and_amount(): void
    {
        $food = $this->category('Jedlo a pitie');
        $restaurant = $this->category('Reštaurácia', $food);
        $this->spend($restaurant, 300, '2026-07-05', 'Večera');
        $this->spend($restaurant, 20, '2026-07-06', 'Obed');
        $this->spend($this->category('Doprava'), 500, '2026-07-07', 'Vlak');

        $toolkit = app(FinanceToolkit::class);

        // filtrovanie podľa skupiny musí zahrnúť aj podkategórie
        $byCategory = $toolkit->call($this->user, 'list_transactions', [
            'from' => '2026-07-01', 'to' => '2026-07-31', 'category_name' => 'Jedlo',
        ]);
        $this->assertCount(2, $byCategory['transakcie']);
        $this->assertSame('Večera', $byCategory['transakcie'][0]['poznamka']);

        $byAmount = $toolkit->call($this->user, 'list_transactions', [
            'from' => '2026-07-01', 'to' => '2026-07-31', 'min_amount' => 250,
        ]);
        $this->assertCount(2, $byAmount['transakcie']);
    }

    public function test_tools_never_reach_another_users_data(): void
    {
        $stranger = User::factory()->create();
        $strangerAccount = Account::create([
            'user_id' => $stranger->id, 'name' => 'Cudzí', 'type' => 'cash', 'balance' => 0, 'color' => '#000000',
        ]);
        $strangerCategory = Category::create([
            'user_id' => $stranger->id, 'name' => 'Tajné', 'type' => 'expense', 'color' => '#000000', 'icon' => '🔒', 'position' => 1,
        ]);
        Transaction::create([
            'user_id' => $stranger->id, 'account_id' => $strangerAccount->id, 'category_id' => $strangerCategory->id,
            'type' => 'expense', 'amount' => 9_999, 'date' => '2026-07-10', 'note' => 'Cudzia platba',
        ]);

        $r = app(FinanceToolkit::class)->call($this->user, 'list_transactions', ['from' => '2026-07-01', 'to' => '2026-07-31']);

        $this->assertSame([], $r['transakcie']);
    }

    public function test_an_unknown_tool_is_reported_not_thrown(): void
    {
        $r = app(FinanceToolkit::class)->call($this->user, 'drop_database', []);

        $this->assertArrayHasKey('error', $r);
    }

    // ── Konverzácia ─────────────────────────────────────────────────────

    public function test_a_tool_call_round_trip_is_saved_in_full(): void
    {
        $this->spend($this->category('Nákupy'), 200, '2026-07-05');

        Http::fakeSequence()
            ->push($this->toolCall('spending_summary', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->push($this->reply('V júli si minul 200 €.'));

        $chat = $this->user->chats()->create();
        $answer = app(Assistant::class)->ask($chat, 'Koľko som minul v júli?');

        $this->assertSame('V júli si minul 200 €.', $answer->content);

        $roles = $chat->messages()->pluck('role')->all();
        $this->assertSame(['user', 'assistant', 'tool', 'assistant'], $roles);

        // výsledok nástroja sa uloží aj s dátami, nech sa konverzácia dá prehrať
        $tool = $chat->messages()->where('role', 'tool')->first();
        $this->assertSame('spending_summary', $tool->name);
        $this->assertStringContainsString('200', $tool->content);
    }

    public function test_the_chat_gets_a_title_from_the_first_question(): void
    {
        Http::fake(['*' => Http::response($this->reply('Jasné.'))]);

        $chat = $this->user->chats()->create();
        app(Assistant::class)->ask($chat, 'Prečo som tento mesiac minul viac ako minulý?');
        app(Assistant::class)->ask($chat, 'A čo predtým?');

        $this->assertSame('Prečo som tento mesiac minul viac ako minulý?', $chat->fresh()->title);
    }

    public function test_an_endless_tool_loop_is_cut_off(): void
    {
        Http::fake(['*' => Http::response($this->toolCall('financial_overview', []))]);

        $chat = $this->user->chats()->create();
        $answer = app(Assistant::class)->ask($chat, 'Ako som na tom?');

        $this->assertStringContainsString('konkrétnejšie', $answer->content);
        $this->assertLessThan(20, $chat->messages()->count());
    }

    public function test_a_dangling_tool_call_does_not_break_the_chat_forever(): void
    {
        $chat = $this->user->chats()->create();
        $chat->messages()->create(['role' => 'user', 'content' => 'Koľko mám?']);
        // kolo sa prerušilo po uložení volania, ale pred uložením výsledku
        $chat->messages()->create([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [['id' => 'call_stratene', 'type' => 'function',
                'function' => ['name' => 'financial_overview', 'arguments' => '{}']]],
        ]);

        Http::fake(['*' => Http::response($this->reply('Máš 1 000 €.'))]);

        $answer = app(Assistant::class)->ask($chat, 'Tak čo teda?');

        $this->assertSame('Máš 1 000 €.', $answer->content);

        // rozbité volanie sa modelu neposlalo
        $sent = collect(Http::recorded()->last()[0]->data()['messages']);
        $this->assertTrue($sent->every(fn ($m) => ! isset($m['tool_calls'])));
    }

    public function test_an_orphaned_tool_result_is_left_out(): void
    {
        $chat = $this->user->chats()->create();
        // volanie vypadlo z okna histórie, výsledok ostal visieť
        $chat->messages()->create([
            'role' => 'tool', 'name' => 'financial_overview',
            'tool_call_id' => 'call_bez_volania', 'content' => '{"zostatok":1000}',
        ]);
        $chat->messages()->create(['role' => 'user', 'content' => 'Koľko mám?']);

        Http::fake(['*' => Http::response($this->reply('Máš 1 000 €.'))]);

        app(Assistant::class)->ask($chat, 'Koľko mám?');

        $sent = collect(Http::recorded()->last()[0]->data()['messages']);
        $this->assertTrue($sent->every(fn ($m) => $m['role'] !== 'tool'));
    }

    public function test_a_tool_call_and_its_result_are_saved_together_or_not_at_all(): void
    {
        Http::fakeSequence()
            ->push($this->toolCall('spending_summary', ['from' => '2026-07-01', 'to' => '2026-07-31']))
            ->push($this->reply('Hotovo.'));

        $chat = $this->user->chats()->create();
        app(Assistant::class)->ask($chat, 'Koľko som minul?');

        // za každým volaním nástroja musí nasledovať jeho výsledok
        $calls = $chat->messages()->whereNotNull('tool_calls')->get()
            ->flatMap(fn ($m) => collect($m->tool_calls)->pluck('id'))->all();
        $answered = $chat->messages()->where('role', 'tool')->pluck('tool_call_id')->all();

        $this->assertSame($calls, $answered);
    }

    public function test_every_request_offers_the_tools_and_forbids_asking_permission(): void
    {
        Http::fake(['*' => Http::response($this->reply('Jasné.'))]);

        app(Assistant::class)->ask($this->user->chats()->create(), 'Čo povieš na 200 € do BTC?');

        $sent = Http::recorded()->last()[0]->data();

        $names = collect($sent['tools'])->pluck('function.name');
        $this->assertContains('investment_portfolio', $names);
        $this->assertContains('financial_overview', $names);

        // bez tohto sa model pýta „chceš, aby som sa pozrel?" namiesto toho, aby sa pozrel
        $this->assertStringContainsString('nepýtaj na dovolenie', $sent['messages'][0]['content']);
    }

    public function test_a_missing_api_key_is_reported_clearly(): void
    {
        config(['services.openai.key' => null]);

        $this->expectExceptionMessage('OPENAI_API_KEY');
        app(Assistant::class)->ask($this->user->chats()->create(), 'Ahoj');
    }

    // ── Stránka a endpoint ──────────────────────────────────────────────

    public function test_the_page_lists_saved_chats(): void
    {
        Chat::create(['user_id' => $this->user->id, 'title' => 'Starý chat', 'last_message_at' => now()]);

        $this->actingAs($this->user)->get('/assistant')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('configured', true)->has('chats', 1));
    }

    public function test_sending_a_message_creates_a_chat_and_returns_the_thread(): void
    {
        Http::fake(['*' => Http::response($this->reply('Ahoj Martin.'))]);

        $this->actingAs($this->user)
            ->postJson('/assistant/send', ['message' => 'Ahoj'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('messages.1.content', 'Ahoj Martin.');

        $this->assertSame(1, $this->user->chats()->count());
    }

    public function test_another_users_chat_cannot_be_opened_or_deleted(): void
    {
        $foreign = Chat::create(['user_id' => User::factory()->create()->id, 'title' => 'Cudzí']);

        $this->actingAs($this->user)->get("/assistant/{$foreign->id}")->assertForbidden();
        $this->actingAs($this->user)->delete("/assistant/{$foreign->id}")->assertForbidden();
        $this->actingAs($this->user)->postJson('/assistant/send', ['message' => 'Ahoj', 'chat_id' => $foreign->id])->assertNotFound();

        $this->assertDatabaseHas('chats', ['id' => $foreign->id]);
    }

    public function test_a_failing_model_does_not_lose_the_question(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);

        $this->actingAs($this->user)
            ->postJson('/assistant/send', ['message' => 'Koľko mám?'])
            ->assertOk()
            ->assertJsonPath('ok', false);

        // otázka ostane v histórii, nech sa dá zopakovať
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => 'Koľko mám?']);
    }

    public function test_the_toolkit_reads_a_real_date_range(): void
    {
        $this->spend($this->category('Nákupy'), 50, CarbonImmutable::today()->toDateString());

        $r = app(FinanceToolkit::class)->call($this->user, 'spending_summary', []);

        $this->assertSame(50.0, $r['vydavky']);
    }
}
