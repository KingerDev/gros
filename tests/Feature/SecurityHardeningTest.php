<?php

namespace Tests\Feature;

use App\Http\Controllers\InvestmentController;
use App\Models\User;
use App\Services\AppleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Mockery;
use Tests\TestCase;

/** Opravy z bezpečnostného auditu (2026-10-18). */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeApple(string $sub = 'apple-123', ?string $email = 'jano@example.com'): void
    {
        $mock = Mockery::mock(AppleIdentity::class);
        $mock->shouldReceive('verify')->andReturn(['sub' => $sub, 'email' => $email]);
        $mock->shouldReceive('refreshToken')->andReturn(null);
        $this->app->instance(AppleIdentity::class, $mock);
    }

    // ---- C1: Apple sa neprepojí s neovereným účtom ----

    public function test_apple_does_not_link_to_unverified_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'jano@example.com']);
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identity_token');

        $this->assertNull($user->fresh()->apple_id);
    }

    public function test_apple_links_to_verified_account(): void
    {
        $user = User::factory()->create(['email' => 'jano@example.com']);
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_apple_user_is_created_verified(): void
    {
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])->assertOk()->assertJsonPath('created', true);

        $this->assertNotNull(User::where('email', 'jano@example.com')->value('email_verified_at'));
    }

    public function test_apple_identity_ignores_email_without_explicit_verification(): void
    {
        $apple = new class extends AppleIdentity
        {
            public function __construct() {}

            protected function keys(): array
            {
                return [];
            }

            protected function decode(string $token, array $keys): array
            {
                return ['iss' => 'https://appleid.apple.com', 'aud' => config('services.apple.client_id'), 'sub' => 'x', 'email' => 'a@b.c'];
            }
        };

        $this->assertNull($apple->verify('jwt')['email']);
    }

    // ---- C2: nové heslo zruší tokeny a ostatné prihlásenia ----

    public function test_password_reset_revokes_tokens_sessions_and_push(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('push_tokens')->insert(['user_id' => $user->id, 'token' => 'ExponentPushToken[x]', 'created_at' => now(), 'updated_at' => now()]);
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('push_tokens')->where('user_id', $user->id)->count());
    }

    public function test_password_change_revokes_app_tokens(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password')]);
        $user->createToken('phone');

        $this->actingAs($user)
            ->put('/settings/password', ['current_password' => 'old-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $user->tokens()->count());
    }

    // ---- C5: investície ----

    public function test_investment_symbol_is_validated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/investments', ['ticker' => 'X', 'name' => 'X', 'kind' => 'etf', 'quote_source' => 'yahoo', 'quote_symbol' => 'a/../b?c=1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quote_symbol');
    }

    public function test_investment_count_is_capped(): void
    {
        $user = User::factory()->create();
        $rows = [];
        for ($i = 0; $i < InvestmentController::MAX_INVESTMENTS; $i++) {
            $rows[] = ['user_id' => $user->id, 'ticker' => "T$i", 'name' => "T$i", 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('investments')->insert($rows);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/investments', ['ticker' => 'NEW', 'name' => 'New', 'kind' => 'etf', 'quote_source' => 'manual', 'current_price' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticker');
    }

    public function test_price_refresh_is_rate_limited(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($token)->postJson('/api/v1/investments/refresh')->assertJsonPath('ok', true);
        }
        $this->withToken($token)->postJson('/api/v1/investments/refresh')->assertJsonPath('ok', false);
    }

    // ---- C13: neplatné obdobie v URL neuviazne v session ----

    public function test_invalid_period_is_ignored_and_not_remembered(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        foreach (['?period=bogus', '?period=month&ref=nonsense', '?period=custom&from=xx', '?period[]=a'] as $query) {
            $this->withToken($token)->getJson('/api/v1/transactions'.$query)->assertOk()->assertJsonPath('period.key', 'month');
        }

        $this->actingAs($user)->get('/transactions?period=custom&from=not-a-date')->assertOk();
        $this->actingAs($user)->get('/transactions')->assertOk();
    }
}
