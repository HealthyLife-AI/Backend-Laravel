<?php

namespace Tests\Feature\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * FR-05's reuse-detection has one deliberate exception: a lost response. The
 * server rotates a refresh token and the client never sees the new pair (a
 * dropped connection), so its retry presents the token it still has — the
 * one the server just revoked. `RefreshTokenService::resolveGraceFrontier()`
 * recognises that shape (a chain of revocations all inside
 * `jwt.refresh_grace_seconds`) and issues a fresh pair instead of revoking
 * everything. See TokenRefreshTest for reuse past the window, which is
 * unchanged.
 */
class RefreshGraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: string} the user and its first refresh token */
    private function loggedIn(): array
    {
        $user = User::factory()->nutritionist()->create();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();

        return [$user, $login['refresh_token']];
    }

    public function test_a_lost_response_retry_inside_the_window_succeeds(): void
    {
        [$user, $first] = $this->loggedIn();

        // The real rotation: the server issues a second token, but we
        // simulate its response never reaching the client by simply
        // never using it — only $first is presented again below.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        // The client, having seen nothing, retries with the same token
        // it already had.
        $retry = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first]);

        $retry->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
        $this->assertNotSame($first, $retry->json('refresh_token'));

        // The session survived: the fresh token actually works, and the
        // user was not logged out everywhere.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $retry->json('refresh_token')])->assertOk();
        $this->assertGreaterThan(
            0,
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }

    public function test_two_retries_for_the_same_lost_response_both_succeed(): void
    {
        [, $first] = $this->loggedIn();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        // Two retries for the one lost response (e.g. the app's own retry
        // logic firing twice). SQLite's single connection in tests can't
        // exercise the row-locking itself, but both must still succeed,
        // distinctly — see resolveGraceFrontier()'s docblock for how the
        // locking that makes this safe under real concurrency works.
        $retryA = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first]);
        $retryB = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first]);

        $retryA->assertOk();
        $retryB->assertOk();
        $this->assertNotSame($retryA->json('refresh_token'), $retryB->json('refresh_token'));

        // Both resulting tokens are live and usable.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $retryA->json('refresh_token')])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $retryB->json('refresh_token')])->assertOk();
    }

    public function test_past_the_window_the_same_retry_revokes_everything(): void
    {
        [$user, $first] = $this->loggedIn();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        $this->travel(config('jwt.refresh_grace_seconds') + 1)->seconds();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();

        $this->assertSame(
            0,
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }

    public function test_an_older_token_further_back_in_the_chain_always_revokes(): void
    {
        [$user, $first] = $this->loggedIn();

        // A real, successful chain: first -> second -> third, with the
        // second rotation happening long after the first (ordinary use,
        // not a retry burst).
        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->json('refresh_token');
        $this->travel(config('jwt.refresh_grace_seconds') + 1)->seconds();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second])->assertOk();

        // $first is now two hops stale, not a lost-response retry for
        // anything live — always revokes, window or not.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();

        $this->assertSame(
            0,
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }

    public function test_a_single_session_logout_is_never_treated_as_a_retry(): void
    {
        [, $first] = $this->loggedIn();

        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $first])->assertOk();

        // Revoked by logout (revoked_at set, replaced_by_id left null —
        // it was never rotated), immediately replayed. No grace: a
        // logged-out token has no rotation chain to resolve.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();
    }

    public function test_grace_uses_are_logged_without_the_token_itself(): void
    {
        [$user, $first] = $this->loggedIn();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        Log::spy();

        $retry = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use ($user, $first, $retry) {
            if (! str_contains($message, 'Refresh grace')) {
                return false;
            }

            $dump = json_encode($context, JSON_UNESCAPED_UNICODE);
            $this->assertSame($user->id, $context['user_id'] ?? null);
            $this->assertArrayHasKey('hops', $context);
            $this->assertStringNotContainsString($first, $dump);
            $this->assertStringNotContainsString($retry->json('refresh_token'), $dump);

            return true;
        })->once();
    }

    public function test_the_grace_window_can_be_disabled(): void
    {
        config(['jwt.refresh_grace_seconds' => 0]);
        [$user, $first] = $this->loggedIn();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertOk();

        // Immediately, same burst — but with the window off, every
        // revoked-token presentation revokes, exactly as before this
        // feature existed.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first])->assertUnauthorized();

        $this->assertSame(
            0,
            RefreshToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count()
        );
    }
}
