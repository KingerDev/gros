<?php

namespace Tests\Feature;

use App\Console\Commands\SendWeeklySummary;
use App\Models\Account;
use App\Models\Category;
use App\Models\PushToken;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Nedeľné push zhrnutie týždňa (gros:weekly-summary). */
class WeeklySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // nedeľa 19:00 v Bratislave
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 17:00:00', 'UTC'));

        $this->user = User::factory()->create();
        $this->account = Account::create(['user_id' => $this->user->id, 'name' => 'Bežný', 'type' => 'cash', 'balance' => 1000, 'color' => '#4c8dff']);
        $this->category = Category::create(['user_id' => $this->user->id, 'name' => 'Potraviny', 'type' => 'expense', 'color' => '#e8544e', 'icon' => 'cart', 'position' => 1]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
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
            'date' => '2026-10-15',
        ], $attrs));
    }

    protected function body(): string
    {
        $service = app(WeeklySummaryService::class);

        return $service->body($this->user, $service->forUser($this->user, CarbonImmutable::parse('2026-10-18')));
    }

    public function test_compares_with_last_week_and_names_the_biggest_expense(): void
    {
        $this->txn(['amount' => 1267.4, 'date' => '2026-10-12']); // pondelok
        $this->txn(['amount' => 45, 'note' => 'Obed', 'date' => '2026-10-17']);
        $this->txn(['amount' => 1400, 'date' => '2026-10-05']); // minulý pondelok
        $this->txn(['amount' => 100, 'date' => '2026-10-04']); // predminulý týždeň — nepočíta sa
        $this->txn(['amount' => 999, 'excluded_from_analytics' => true]);

        $this->assertSame('Minul si 1 312,40 € — o 6 % menej ako minulý týždeň. Najväčší výdavok: Potraviny 1 267,40 €.', $this->body());
    }

    public function test_more_than_last_week_and_note_as_name(): void
    {
        $this->txn(['amount' => 150, 'note' => 'Obed']);
        $this->txn(['amount' => 100, 'date' => '2026-10-06']);

        $this->assertSame('Minul si 150,00 € — o 50 % viac ako minulý týždeň. Najväčší výdavok: Obed 150,00 €.', $this->body());
    }

    public function test_same_as_last_week(): void
    {
        $this->txn(['amount' => 100]);
        $this->txn(['amount' => 100, 'date' => '2026-10-06']);

        $this->assertStringContainsString('— rovnako ako minulý týždeň.', $this->body());
    }

    public function test_without_last_week_there_is_no_comparison(): void
    {
        $this->txn(['amount' => 20, 'note' => 'Káva']);

        $this->assertSame('Minul si 20,00 €. Najväčší výdavok: Káva 20,00 €.', $this->body());
    }

    public function test_no_expenses(): void
    {
        $this->txn(['type' => 'income', 'amount' => 2000]);

        $this->assertSame('Tento týždeň si nemal žiadne výdavky.', $this->body());
    }

    public function test_refund_lowers_the_expense(): void
    {
        $this->txn(['amount' => 100, 'note' => 'Topánky'])->forceFill(['refunded_amount' => 30])->save();

        $this->assertSame('Minul si 70,00 €. Najväčší výdavok: Topánky 70,00 €.', $this->body());
    }

    public function test_privacy_mode_hides_amounts(): void
    {
        $this->user->update(['privacy_mode' => true]);
        $this->txn(['amount' => 100]);

        $this->assertStringNotContainsString('€', $this->body());
    }

    public function test_command_sends_one_message_per_device(): void
    {
        Http::fake([SendWeeklySummary::EXPO_URL => Http::response(['data' => [['status' => 'ok', 'id' => 'a'], ['status' => 'ok', 'id' => 'b']]])]);
        PushToken::create(['user_id' => $this->user->id, 'token' => 'ExponentPushToken[1]', 'platform' => 'ios']);
        PushToken::create(['user_id' => $this->user->id, 'token' => 'ExponentPushToken[2]', 'platform' => 'ios']);
        User::factory()->create(); // bez zariadenia — nič sa mu neposiela
        $this->txn(['amount' => 45, 'note' => 'Obed']);

        $this->artisan('gros:weekly-summary')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === SendWeeklySummary::EXPO_URL
                && count($data) === 2
                && $data[0] === [
                    'to' => 'ExponentPushToken[1]',
                    'title' => 'Tvoj týždeň v Groši',
                    'body' => 'Minul si 45,00 €. Najväčší výdavok: Obed 45,00 €.',
                    'sound' => 'default',
                    'data' => ['url' => '/'],
                ]
                && $data[1]['to'] === 'ExponentPushToken[2]';
        });
        $this->assertSame(2, PushToken::count());
    }

    public function test_unregistered_device_is_removed(): void
    {
        Http::fake([SendWeeklySummary::EXPO_URL => Http::response(['data' => [
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'b'],
        ]])]);
        PushToken::create(['user_id' => $this->user->id, 'token' => 'ExponentPushToken[old]']);
        PushToken::create(['user_id' => $this->user->id, 'token' => 'ExponentPushToken[new]']);

        $this->artisan('gros:weekly-summary')->assertSuccessful();

        $this->assertSame(['ExponentPushToken[new]'], PushToken::pluck('token')->all());
    }

    public function test_dry_run_sends_nothing(): void
    {
        Http::fake();
        PushToken::create(['user_id' => $this->user->id, 'token' => 'ExponentPushToken[1]']);

        $this->artisan('gros:weekly-summary --dry-run')
            ->expectsOutputToContain('Tento týždeň si nemal žiadne výdavky.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
