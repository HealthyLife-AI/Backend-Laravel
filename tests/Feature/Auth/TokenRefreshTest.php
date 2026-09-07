<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * FR-05: securely refresh the authentication token. Covers rotation
 * (single-use) and reuse detection (theft response).
 */
class TokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_valid_refresh_token_issues_a_new_token_pair_and_revokes_the_old_one(): void
    {
        $user = User::factory()->nutritionist()->create();
        $original = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();

        $rotated = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $original['refresh_token'],
        ]);

        $rotated->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
        $this->assertNotSame($original['refresh_token'], $rotated->json('refresh_token'));
        $this->assertNotSame($original['access_token'], $rotated->json('access_token'));

        // The old refresh token is now revoked.
        $stored = RefreshToken::query()->where('token_hash', hash('sha256', $original['refresh_token']))->firstOrFail();
        $this->assertTrue($stored->isRevoked());
    }

    public function test_reusing_an_already_rotated_refresh_token_revokes_every_session(): void
    {
        $user = User::factory()->nutritionist()->create();
        $first = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();

        $second = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->json();

        // Replay the already-consumed first token: treated as theft.
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->assertUnauthorized();

        // The legitimately-rotated second token is now dead too — the
        // whole family was revoked as a precaution.
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $second['refresh_token'],
        ])->assertUnauthorized();

        $this->assertSame(
            0,
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }

    public function test_an_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'not-a-real-token',
        ])->assertUnauthorized();
    }

    public function test_an_expired_refresh_token_is_rejected(): void
    {
        $user = User::factory()->nutritionist()->create();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();

        RefreshToken::query()
            ->where('token_hash', hash('sha256', $login['refresh_token']))
            ->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertUnauthorized();
    }
}
