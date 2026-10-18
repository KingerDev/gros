<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\AppleIdentity;
use App\Support\DefaultCategories;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

/** Registrácia, Sign in with Apple, zabudnuté heslo a jazyk API pre mobilnú appku. */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    /** Podvrhne overenie Apple tokenu — vráti dané Apple ID a e-mail. */
    protected function fakeApple(?string $sub = 'apple-123', ?string $email = 'jano@privaterelay.appleid.com', ?string $refresh = null): void
    {
        $mock = Mockery::mock(AppleIdentity::class);
        if ($sub === null) {
            $mock->shouldReceive('verify')->andThrow(new UnexpectedValueException('bad'));
        } else {
            $mock->shouldReceive('verify')->andReturn(['sub' => $sub, 'email' => $email]);
        }
        $mock->shouldReceive('refreshToken')->andReturn($refresh);
        $this->app->instance(AppleIdentity::class, $mock);
    }

    // ---- registrácia ----

    public function test_register_returns_a_token_and_seeds_categories(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Jano', 'email' => 'jano@example.com', 'password' => 'Secret-pass1', 'device_name' => 'iPhone',
        ], ['Accept-Language' => 'sk-SK'])
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.has_password', true)
            ->assertJsonPath('user.apple', false)
            ->assertJsonPath('user.locale', 'sk')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.apple_id')
            ->assertJsonMissingPath('user.apple_refresh_token');

        $user = User::where('email', 'jano@example.com')->firstOrFail();
        $this->assertSame('sk', $user->locale);
        $this->assertTrue($user->categories()->where('name', 'Potraviny')->exists());
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_register_in_english_seeds_english_categories(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'John', 'email' => 'john@example.com', 'password' => 'Secret-pass1',
        ], ['Accept-Language' => 'en-US,en;q=0.9'])->assertCreated();

        $user = User::where('email', 'john@example.com')->firstOrFail();
        $this->assertSame('en', $user->locale);
        $this->assertTrue($user->categories()->where('name', 'Groceries')->exists());
        $this->assertTrue($user->categories()->where('name', 'Housing')->exists());
        $this->assertFalse($user->categories()->where('name', 'Potraviny')->exists());
        // všetky východiskové kategórie majú preklad
        $this->assertSame(0, $user->categories()->whereIn('name', ['Bývanie', 'Príjmy', 'Ostatné'])->count());
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', ['name' => '', 'email' => 'taken@example.com', 'password' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_registered_user_can_log_in(): void
    {
        $this->postJson('/api/v1/register', ['name' => 'Jano', 'email' => 'jano@example.com', 'password' => 'Secret-pass1'])->assertCreated();

        $this->postJson('/api/v1/login', ['email' => 'jano@example.com', 'password' => 'Secret-pass1'])
            ->assertOk()
            ->assertJsonPath('user.has_password', true);
    }

    // ---- Sign in with Apple ----

    public function test_apple_creates_a_new_user_without_password(): void
    {
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt', 'name' => 'Ján Novák'], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('user.name', 'Ján Novák')
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.apple', true)
            ->assertJsonMissingPath('user.apple_id')
            ->assertJsonStructure(['token']);

        $user = User::where('apple_id', 'apple-123')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertSame('jano@privaterelay.appleid.com', $user->email);
        $this->assertSame('en', $user->locale);
        $this->assertTrue($user->categories()->where('name', 'Groceries')->exists());
    }

    public function test_apple_name_falls_back_to_email_prefix(): void
    {
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertOk()
            ->assertJsonPath('user.name', 'jano');
    }

    public function test_apple_signs_in_existing_apple_user(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['apple_id' => 'apple-123'])->save();
        $this->fakeApple(email: null); // pri ďalších prihláseniach Apple e-mail neposiela

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame(1, User::count());
    }

    public function test_apple_links_existing_account_by_email(): void
    {
        $user = User::factory()->create(['email' => 'jano@privaterelay.appleid.com', 'password' => bcrypt('secret-pass')]);
        DefaultCategories::seed($user);
        $categories = $user->categories()->count();
        $this->fakeApple();

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.has_password', true)
            ->assertJsonPath('user.apple', true);

        $this->assertSame('apple-123', $user->fresh()->apple_id);
        $this->assertSame($categories, $user->categories()->count());
    }

    public function test_apple_stores_encrypted_refresh_token(): void
    {
        $this->fakeApple(refresh: 'apple-refresh');

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt', 'authorization_code' => 'code'])->assertOk();

        $user = User::where('apple_id', 'apple-123')->firstOrFail();
        $this->assertSame('apple-refresh', $user->apple_refresh_token);
        $this->assertNotSame('apple-refresh', $user->getRawOriginal('apple_refresh_token'));
    }

    public function test_apple_invalid_token_is_422(): void
    {
        $this->fakeApple(sub: null);

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identity_token');

        $this->assertSame(0, User::count());
    }

    public function test_apple_without_email_and_unknown_user_is_422(): void
    {
        $this->fakeApple(email: null);

        $this->postJson('/api/v1/auth/apple', ['identity_token' => 'jwt'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identity_token');
    }

    public function test_apple_requires_identity_token(): void
    {
        $this->postJson('/api/v1/auth/apple', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identity_token');
    }

    public function test_apple_only_user_cannot_log_in_with_password(): void
    {
        $user = User::factory()->create(['email' => 'a@b.sk', 'password' => null]);
        $user->forceFill(['apple_id' => 'x'])->save();

        $this->postJson('/api/v1/login', ['email' => 'a@b.sk', 'password' => ''])->assertStatus(422);
        $this->postJson('/api/v1/login', ['email' => 'a@b.sk', 'password' => 'anything'])->assertStatus(422);
    }

    // ---- zabudnuté heslo ----

    public function test_forgot_password_sends_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'jano@example.com']);

        $this->postJson('/api/v1/forgot-password', ['email' => 'jano@example.com'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'jano@example.com']);

        $known = $this->postJson('/api/v1/forgot-password', ['email' => 'jano@example.com'])->json();
        $unknown = $this->postJson('/api/v1/forgot-password', ['email' => 'nobody@example.com'])->json();

        $this->assertSame($known, $unknown);
    }

    public function test_forgot_password_validates_email(): void
    {
        $this->postJson('/api/v1/forgot-password', ['email' => 'nie-je-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ---- jazyk ----

    public function test_locale_is_saved_for_logged_in_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->assertSame('sk', $user->fresh()->locale);

        $this->withToken($token)->getJson('/api/v1/dashboard', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertOk();
        $this->assertSame('en', $user->fresh()->locale);

        $this->withToken($token)->getJson('/api/v1/dashboard', ['Accept-Language' => 'cs-CZ'])->assertOk();
        $this->assertSame('sk', $user->fresh()->locale);
    }

    public function test_missing_header_keeps_users_locale(): void
    {
        $user = User::factory()->create(['locale' => 'sk']);
        $token = $user->createToken('test')->plainTextToken;

        // testovací klient posiela Accept-Language: en-us — tu ho odoberieme
        $this->withToken($token)->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertJsonPath('catalog.kindLabels.stock', 'Akcia');

        $this->assertSame('sk', $user->fresh()->locale);
    }

    public function test_api_strings_follow_the_device_language(): void
    {
        $user = User::factory()->create();
        DefaultCategories::seed($user);
        Account::create(['user_id' => $user->id, 'name' => 'Hlavný', 'type' => 'Bežný účet', 'balance' => 10, 'color' => '#000000']);
        $token = $user->createToken('test')->plainTextToken;

        $en = $this->withToken($token)->getJson('/api/v1/accounts?period=all', ['Accept-Language' => 'en-GB']);
        $en->assertOk()
            ->assertJsonPath('accounts.0.type', 'Bežný účet')
            ->assertJsonPath('accounts.0.type_label', 'Checking account')
            ->assertJsonPath('catalog.kindLabels.stock', 'Stock')
            ->assertJsonPath('catalog.accountTypes.0', ['value' => 'Bežný účet', 'label' => 'Checking account']);

        $this->withToken($token)->getJson('/api/v1/accounts', ['Accept-Language' => 'sk'])
            ->assertJsonPath('accounts.0.type_label', 'Bežný účet')
            ->assertJsonPath('catalog.kindLabels.stock', 'Akcia');

        $this->withToken($token)->getJson('/api/v1/dashboard?period=month&ref=2026-01', ['Accept-Language' => 'en'])
            ->assertJsonPath('period.label', 'January 2026')
            ->assertJsonPath('prevStats.label', 'December 2025');

        $this->withToken($token)->getJson('/api/v1/dashboard?period=month&ref=2026-01', ['Accept-Language' => 'sk'])
            ->assertJsonPath('period.label', 'Január 2026')
            ->assertJsonPath('prevStats.label', 'December 2025');
    }

    public function test_web_stays_slovak(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->get('/dashboard?period=all', ['Accept-Language' => 'en-US'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.label', 'Celé obdobie')
                ->where('catalog.kindLabels.stock', 'Akcia'));
    }

    public function test_seeded_user_categories_are_not_reseeded(): void
    {
        $user = User::factory()->create(['locale' => 'en']);
        Category::create(['user_id' => $user->id, 'name' => 'Vlastná', 'type' => 'expense', 'color' => '#000000', 'position' => 0]);

        DefaultCategories::seed($user);

        $this->assertSame(1, $user->categories()->count());
    }
}
