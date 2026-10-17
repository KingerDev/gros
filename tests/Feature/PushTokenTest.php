<?php

namespace Tests\Feature;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Registrácia zariadenia mobilnej appky na push notifikácie. */
class PushTokenTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'ExponentPushToken[abc123]';

    public function test_token_is_saved(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/push-token', ['token' => self::TOKEN, 'platform' => 'ios'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => null]);

        $this->assertDatabaseHas('push_tokens', ['user_id' => $user->id, 'token' => self::TOKEN, 'platform' => 'ios']);
    }

    public function test_token_of_another_user_moves_to_the_current_one(): void
    {
        $other = User::factory()->create();
        PushToken::create(['user_id' => $other->id, 'token' => self::TOKEN]);
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/push-token', ['token' => self::TOKEN])
            ->assertOk();

        $this->assertSame(1, PushToken::count());
        $this->assertSame($user->id, PushToken::first()->user_id);
    }

    public function test_token_is_deleted(): void
    {
        $user = User::factory()->create();
        PushToken::create(['user_id' => $user->id, 'token' => self::TOKEN]);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->deleteJson('/api/v1/push-token', ['token' => self::TOKEN])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => null]);

        $this->assertSame(0, PushToken::count());
    }

    public function test_cannot_delete_token_of_another_user(): void
    {
        $other = User::factory()->create();
        PushToken::create(['user_id' => $other->id, 'token' => self::TOKEN]);
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->deleteJson('/api/v1/push-token', ['token' => self::TOKEN])
            ->assertOk();

        $this->assertSame(1, PushToken::count());
    }

    public function test_wrong_format_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/push-token', ['token' => 'nejaky-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');

        $this->assertSame(0, PushToken::count());
    }

    public function test_needs_a_token(): void
    {
        $this->postJson('/api/v1/push-token', ['token' => self::TOKEN])->assertUnauthorized();
    }
}
