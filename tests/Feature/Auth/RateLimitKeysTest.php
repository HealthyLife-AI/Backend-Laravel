<?php

namespace Tests\Feature\Auth;

use App\Models\Subscriber;
use App\Models\User;
use App\Services\Clients\ClientInviteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2: each unauthenticated auth route has its own named limiter. The bare
 * `throttle:N,1` form shared one counter per IP across all of them.
 */
class RateLimitKeysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $nutritionist = User::factory()->nutritionist()->create();
        $patient = Subscriber::factory()->active()->forNutritionist($nutritionist)->create();
        $this->user = $patient->user;
        $this->user->forceFill(['username' => 'sara.k', 'password' => 'Right789A'])->save();
    }

    private function login(string $username, string $password = 'wrong'): int
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password])->status();
    }

    public function test_many_refreshes_do_not_block_login_or_activation(): void
    {
        $refresh = $this->postJson('/api/v1/auth/login', ['username' => 'sara.k', 'password' => 'Right789A'])->assertOk()->json('refresh_token');

        // 25 refreshes from one address (the old shared counter stopped at 10 for login, 20 for refresh).
        for ($i = 0; $i < 25; $i++) {
            $refresh = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk()->json('refresh_token');
        }

        $this->postJson('/api/v1/auth/login', ['username' => 'sara.k', 'password' => 'Right789A'])->assertOk();
        $pending = Subscriber::factory()->forNutritionist(User::factory()->nutritionist()->create())->create();
        $token = app(ClientInviteService::class)->issue($pending)['plain'];
        $this->postJson("/api/v1/invites/{$token}/activate", ['password' => 'Fresh789A', 'password_confirmation' => 'Fresh789A'])->assertOk();
    }

    public function test_login_is_limited_per_address_and_account(): void
    {
        // Lockout would answer 423 after 5 failures: use names with no account so only the limiter speaks.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(401, $this->login('nobody.one'));
        }
        $this->assertSame(429, $this->login('nobody.one'));
        // Same normalised account typed differently: same counter.
        $this->assertSame(429, $this->login(' NOBODY.ONE '));
        // Another account from the same address is not blocked.
        $this->assertSame(401, $this->login('nobody.two'));
        // Nor the same account from another address.
        $this->assertSame(401, $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->postJson('/api/v1/auth/login', ['username' => 'nobody.one', 'password' => 'x'])->status());
    }

    public function test_login_has_a_per_address_cap_across_accounts(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->assertSame(401, $this->login("nobody.{$i}"));
        }

        $this->assertSame(429, $this->login('nobody.new'));
    }

    public function test_refresh_is_limited_per_token(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(401, $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'not-a-token'])->status());
        }

        $this->assertSame(429, $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'not-a-token'])->status());
        $this->assertSame(401, $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'another-token'])->status());
    }

    public function test_the_account_lockout_still_answers_423(): void
    {
        for ($i = 0; $i < config('jwt.max_login_attempts'); $i++) {
            $this->login('sara.k');
        }

        $this->assertSame(423, $this->login('sara.k', 'Right789A'));
    }
}
