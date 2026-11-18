<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Issues and rotates opaque refresh tokens (FR-05).
 *
 * Rotation model: every refresh consumes the presented token and issues a
 * brand-new one in its place (`replaced_by_id` links the chain). A token
 * is single-use. If a token that has already been rotated away is
 * presented again, that's normally a signal of theft/replay — the whole
 * family (every refresh token the user holds) is revoked, forcing
 * re-login everywhere.
 *
 * One exception: a lost response. The server can rotate a token and the
 * client never sees the new pair (a dropped connection), so its retry
 * presents the token it still has — the one the server just revoked.
 * `resolveGraceFrontier()` recognises that specific shape (a chain of
 * revocations that all happened within `jwt.refresh_grace_seconds`) and
 * rotates from the chain's live end instead of revoking. Reuse of a
 * token further back, or past the window, is unchanged: revoke
 * everything.
 */
class RefreshTokenService
{
    /**
     * Caps how far resolveGraceFrontier() ever walks the chain, so a
     * degenerate chain (should one ever exist) fails closed to
     * revoke-everything instead of looping. A genuine lost-response
     * retry burst is one or two hops; this is headroom, not a limit
     * anyone should approach in practice.
     */
    private const GRACE_MAX_HOPS = 5;

    /**
     * Issue a brand-new refresh token for a fresh login (no prior token
     * to rotate from).
     *
     * @return array{plain: string, model: RefreshToken}
     */
    public function issue(User $user, Request $request): array
    {
        return $this->create($user, $request);
    }

    /**
     * Exchange a valid, unused refresh token for a new access + refresh
     * token pair, rotating the old one out.
     *
     * @return array{plain: string, model: RefreshToken, user: User}
     *
     * @throws InvalidRefreshTokenException
     */
    public function rotate(string $plainToken, Request $request): array
    {
        $hash = $this->hash($plainToken);

        // The reject branches (not found, expired, genuine reuse) return a
        // plain value instead of throwing from inside the transaction:
        // throwing here would roll the transaction back, and
        // revokeAllForUser()'s revocation — the whole point of the reuse
        // branch — must survive even though the request itself is about
        // to be refused. Only the success path's write needs to be
        // atomic with the lock below.
        $outcome = DB::transaction(function () use ($hash, $request) {
            // Locked for the rest of this transaction: a second request
            // presenting this same token (a concurrent retry) blocks here
            // until this one commits, rather than racing it to the grace
            // walk below.
            /** @var RefreshToken|null $existing */
            $existing = RefreshToken::query()->where('token_hash', $hash)->lockForUpdate()->first();

            if ($existing === null || $existing->isExpired()) {
                return ['ok' => false, 'revoke_user' => null];
            }

            if ($existing->isRevoked()) {
                $frontier = $this->resolveGraceFrontier($existing);

                if ($frontier === null) {
                    // Reuse of an already-rotated (or already-logged-out)
                    // token, outside any grace window. Treat as
                    // compromise: kill every session this user holds.
                    return ['ok' => false, 'revoke_user' => $existing->user];
                }

                // Never log the token itself — only that a grace rotation
                // happened, for whom, and how many hops it took.
                Log::info('Refresh grace: lost-response retry served a fresh token pair instead of revoking.', [
                    'user_id' => $existing->user_id,
                    'hops' => $frontier['hops'],
                ]);

                $existing = $frontier['token'];
            }

            ['plain' => $plain, 'model' => $new] = $this->create($existing->user, $request);

            $existing->forceFill([
                'revoked_at' => now(),
                'replaced_by_id' => $new->id,
            ])->save();

            return ['ok' => true, 'plain' => $plain, 'model' => $new, 'user' => $existing->user];
        });

        if (! $outcome['ok']) {
            if ($outcome['revoke_user'] !== null) {
                $this->revokeAllForUser($outcome['revoke_user']);

                throw new InvalidRefreshTokenException(
                    'Refresh token was already used. All sessions have been revoked as a precaution.'
                );
            }

            throw new InvalidRefreshTokenException;
        }

        return ['plain' => $outcome['plain'], 'model' => $outcome['model'], 'user' => $outcome['user']];
    }

    /**
     * Walks the rotation chain forward from an already-revoked token,
     * following `replaced_by_id`, as long as every hop was revoked
     * inside `jwt.refresh_grace_seconds` — the signature of a burst of
     * retries for one lost response, not genuine reuse. Each row is
     * locked as it's reached, so a concurrent walk starting from the
     * same original token (another retry of the same lost response)
     * serializes against this one instead of rotating the same row
     * twice.
     *
     * Stops at, and returns, the first not-yet-revoked token in the
     * chain — the live end a fresh pair should be rotated from. Returns
     * null (genuine reuse, caller revokes everything) when: the window
     * has passed for a hop, a hop was revoked outside rotation (no
     * `replaced_by_id` — a logout, not a retry), the chain runs out, or
     * the hop cap is hit.
     *
     * @return array{token: RefreshToken, hops: int}|null
     */
    private function resolveGraceFrontier(RefreshToken $token): ?array
    {
        $grace = (int) config('jwt.refresh_grace_seconds');

        if ($grace <= 0) {
            return null;
        }

        $current = $token;
        $hops = 0;

        while ($current->isRevoked()) {
            if ($current->replaced_by_id === null || $current->revoked_at === null || $current->revoked_at->diffInSeconds(now()) > $grace) {
                return null;
            }

            if (++$hops > self::GRACE_MAX_HOPS) {
                return null;
            }

            $current = RefreshToken::query()->whereKey($current->replaced_by_id)->lockForUpdate()->first();

            if ($current === null) {
                return null;
            }
        }

        return ['token' => $current, 'hops' => $hops];
    }

    /**
     * Revoke a single refresh token (used on single-session logout).
     */
    public function revoke(RefreshToken $token): void
    {
        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Revoke every active refresh token for a user (used on reuse
     * detection, and available for a future "log out everywhere").
     */
    public function revokeAllForUser(User $user): void
    {
        $user->refreshTokens()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Ends every session at once (password change, activated sign-in link):
     * refresh tokens are deleted and the session version moves on, so every
     * access token issued before, whatever its second, is refused by
     * JwtAuthenticate.
     *
     * Deleted rather than revoked: a stale device presenting a REVOKED token
     * looks like token theft to rotate() and would end every session again,
     * including the one just opened. A deleted token is a plain 401.
     */
    public function endAllSessions(User $user): void
    {
        $user->refreshTokens()->delete();
        $user->increment('session_version');
    }

    public function findValidByPlainToken(string $plainToken): ?RefreshToken
    {
        $token = RefreshToken::query()->where('token_hash', $this->hash($plainToken))->first();

        return $token !== null && $token->isValid() ? $token : null;
    }

    /**
     * @return array{plain: string, model: RefreshToken}
     */
    private function create(User $user, Request $request): array
    {
        $plain = Str::random(80);

        $model = $user->refreshTokens()->create([
            'token_hash' => $this->hash($plain),
            'expires_at' => now()->addMinutes(config('jwt.refresh_ttl')),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'ip_address' => $request->ip(),
        ]);

        return ['plain' => $plain, 'model' => $model];
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
