<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API mobilnej appky — tie isté controllery ako web, len cez token a JSON. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithToken(): array
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);
        DefaultCategories::seed($user);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_login_returns_a_token(): void
    {
        User::factory()->create(['email' => 'a@b.sk', 'password' => bcrypt('secret-pass')]);

        $this->postJson('/api/v1/login', ['email' => 'a@b.sk', 'password' => 'secret-pass', 'device_name' => 'iPhone'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@b.sk', 'password' => bcrypt('secret-pass')]);

        $this->postJson('/api/v1/login', ['email' => 'a@b.sk', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_pages_need_a_token(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_a_page_comes_back_as_its_props_plus_shared_data(): void
    {
        [, $token] = $this->userWithToken();

        $this->withToken($token)->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats' => ['netWorth', 'income', 'expense'], 'plan', 'upcoming', 'categories', 'settings'])
            ->assertJsonMissingPath('component')
            ->assertJsonStructure(['shared' => ['categories', 'events', 'settings']]);
    }

    public function test_an_action_returns_ok_instead_of_a_redirect(): void
    {
        [$user, $token] = $this->userWithToken();

        $this->withToken($token)->postJson('/api/v1/accounts', ['name' => 'Hotovosť', 'type' => 'Hotovosť', 'balance' => 50, 'color' => '#4c8dff'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, Account::where('user_id', $user->id)->count());
    }

    public function test_validation_errors_are_json(): void
    {
        [, $token] = $this->userWithToken();

        $this->withToken($token)->postJson('/api/v1/accounts', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_another_users_data_stays_out_of_reach(): void
    {
        [, $token] = $this->userWithToken();
        $other = User::factory()->create();
        $account = Account::create(['user_id' => $other->id, 'name' => 'Cudzí', 'type' => 'x', 'balance' => 1, 'color' => '#000000']);

        $this->withToken($token)->getJson("/api/v1/accounts/{$account->id}")->assertForbidden();
    }

    public function test_the_period_is_taken_from_the_query(): void
    {
        [, $token] = $this->userWithToken();

        $this->withToken($token)->getJson('/api/v1/analytics?period=year')
            ->assertOk()
            ->assertJsonPath('period.key', 'year');
    }

    public function test_logout_revokes_the_token(): void
    {
        [$user, $token] = $this->userWithToken();

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
