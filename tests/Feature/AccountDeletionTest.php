<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppleIdentity;
use App\Support\DefaultCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Zmazanie účtu z mobilnej appky (DELETE /api/v1/account) — so všetkými dátami. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** Naplní používateľovi dáta vo všetkých tabuľkách, ktoré mu patria. */
    protected function fillData(User $user): void
    {
        DefaultCategories::seed($user);
        $now = now();
        $id = $user->id;
        $category = $user->categories()->whereNotNull('parent_id')->value('id');

        $account = DB::table('accounts')->insertGetId(['user_id' => $id, 'name' => 'Účet', 'type' => 'Bežný účet', 'balance' => 100, 'color' => '#000000', 'created_at' => $now, 'updated_at' => $now]);
        $account2 = DB::table('accounts')->insertGetId(['user_id' => $id, 'name' => 'Sporiaci', 'type' => 'Sporiaci účet', 'balance' => 5, 'color' => '#000000', 'created_at' => $now, 'updated_at' => $now]);
        $event = DB::table('events')->insertGetId(['user_id' => $id, 'name' => 'Dovolenka', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-10', 'created_at' => $now, 'updated_at' => $now]);
        $txn = DB::table('transactions')->insertGetId(['user_id' => $id, 'account_id' => $account, 'category_id' => $category, 'event_id' => $event, 'type' => 'expense', 'amount' => 10, 'date' => '2026-08-02', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('transactions')->insert(['user_id' => $id, 'account_id' => $account, 'category_id' => $category, 'refund_for_id' => $txn, 'type' => 'income', 'amount' => 5, 'date' => '2026-08-03', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('transactions')->insert(['user_id' => $id, 'account_id' => $account, 'to_account_id' => $account2, 'type' => 'transfer', 'amount' => 5, 'date' => '2026-08-03', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('budgets')->insert(['user_id' => $id, 'category_id' => $category, 'limit_amount' => 100, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('subscriptions')->insert(['user_id' => $id, 'account_id' => $account, 'category_id' => $category, 'name' => 'Netflix', 'amount' => 10, 'next_payment' => '2026-11-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('loans')->insert(['user_id' => $id, 'account_id' => $account, 'category_id' => $category, 'name' => 'Hypotéka', 'next_payment' => '2026-11-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('goals')->insert(['user_id' => $id, 'name' => 'Auto', 'target_amount' => 1000, 'created_at' => $now, 'updated_at' => $now]);
        $inv = DB::table('investments')->insertGetId(['user_id' => $id, 'ticker' => 'VWCE', 'name' => 'VWCE', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('investment_transactions')->insert(['investment_id' => $inv, 'units' => 1, 'price' => 100, 'date' => '2026-08-01', 'created_at' => $now, 'updated_at' => $now]);
        $chat = DB::table('chats')->insertGetId(['user_id' => $id, 'title' => 'Chat', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('chat_messages')->insert(['chat_id' => $chat, 'role' => 'user', 'content' => 'ahoj', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('push_tokens')->insert(['user_id' => $id, 'token' => 'ExponentPushToken['.$id.']', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('sessions')->insert(['id' => 'sess-'.$id, 'user_id' => $id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => $now]);
        $user->createToken('another-device');
    }

    /** Počet riadkov, ktoré ešte patria používateľovi — v KAŽDEJ tabuľke so stĺpcom user_id. */
    protected function remainingRows(int $userId, string $email): array
    {
        $left = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (Schema::hasColumn($table, 'user_id') && ($n = DB::table($table)->where('user_id', $userId)->count())) {
                $left[$table] = $n;
            }
        }

        $left['users'] = DB::table('users')->where('id', $userId)->count();
        $left['personal_access_tokens'] = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $userId)->count();
        $left['password_reset_tokens'] = DB::table('password_reset_tokens')->where('email', $email)->count();
        // tabuľky bez user_id — cez rodiča (ostal len cudzí používateľ)
        $left['investment_transactions'] = DB::table('investment_transactions')->whereNotIn('investment_id', DB::table('investments')->pluck('id'))->count();
        $left['chat_messages'] = DB::table('chat_messages')->whereNotIn('chat_id', DB::table('chats')->pluck('id'))->count();

        return array_filter($left);
    }

    public function test_deletes_user_with_all_data(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);
        $this->fillData($user);
        $other = User::factory()->create();
        $this->fillData($other);
        $token = $user->createToken('iPhone')->plainTextToken;

        $this->assertNotEmpty($this->remainingRows($user->id, $user->email));

        $this->withToken($token)->deleteJson('/api/v1/account', ['password' => 'secret-pass'])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->remainingRows($user->id, $user->email));

        // cudzí používateľ zostal nedotknutý
        $this->assertSame(1, DB::table('investment_transactions')->count());
        $this->assertSame(1, DB::table('chat_messages')->count());
        $this->assertSame(3, DB::table('transactions')->where('user_id', $other->id)->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $other->email)->count());
    }

    public function test_token_stops_working_after_deletion(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);
        $token = $user->createToken('iPhone')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/account', ['password' => 'secret-pass'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson('/api/v1/account', ['password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
    }

    public function test_password_is_required_when_user_has_one(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson('/api/v1/account')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
    }

    public function test_apple_only_user_needs_no_password_and_is_revoked(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['apple_id' => 'apple-1', 'apple_refresh_token' => 'r-token'])->save();
        $this->fillData($user);

        $apple = Mockery::mock(AppleIdentity::class);
        $apple->shouldReceive('verify')->andReturn(['sub' => 'apple-1', 'email' => null]);
        $apple->shouldReceive('revoke')->once()->with('r-token');
        $this->app->instance(AppleIdentity::class, $apple);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson('/api/v1/account', ['identity_token' => 'jwt'])
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame([], $this->remainingRows($user->id, $user->email));
    }

    public function test_apple_revoke_failure_does_not_block_deletion(): void
    {
        config(['services.apple.team_id' => 'T', 'services.apple.key_id' => 'K', 'services.apple.private_key' => 'not-a-key']);
        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['apple_id' => 'apple-1', 'apple_refresh_token' => 'r-token'])->save();
        $this->partialMock(AppleIdentity::class, fn ($m) => $m->shouldReceive('verify')->andReturn(['sub' => 'apple-1', 'email' => null]));

        $this->withToken($user->createToken('t')->plainTextToken)
            ->deleteJson('/api/v1/account', ['identity_token' => 'jwt'])
            ->assertOk();

        $this->assertModelMissing($user);
    }

    public function test_apple_only_user_must_confirm_with_apple(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['apple_id' => 'apple-1'])->save();
        $token = $user->createToken('t')->plainTextToken;

        // bez tokenu
        $this->withToken($token)->deleteJson('/api/v1/account')->assertStatus(422)->assertJsonValidationErrors('identity_token');

        // Apple token iného Apple ID
        $apple = Mockery::mock(AppleIdentity::class);
        $apple->shouldReceive('verify')->andReturn(['sub' => 'someone-else', 'email' => null]);
        $this->app->instance(AppleIdentity::class, $apple);
        $this->withToken($token)->deleteJson('/api/v1/account', ['identity_token' => 'jwt'])->assertStatus(422)->assertJsonValidationErrors('identity_token');

        $this->assertModelExists($user);
    }

    public function test_web_deletion_removes_all_data(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);
        $this->fillData($user);

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'secret-pass'])
            ->assertRedirect('/');

        $this->assertSame([], $this->remainingRows($user->id, $user->email));
    }

    public function test_needs_a_token(): void
    {
        $this->deleteJson('/api/v1/account')->assertUnauthorized();
    }
}
