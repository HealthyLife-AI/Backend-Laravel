<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and rotates opaque refresh tokens (FR-05).
 *
 * Rotation model: every refresh consumes the presented token and issues a
 * brand-new one in its place (`replaced_by_id` links the chain). A token
 * is single-use. If a token that has already been rotated away is
 * presented again, that's a signal of theft/replay — the whole family
 * (every refresh token the user holds) is revoked, forcing re-login
 * everywhere.
 */
class RefreshTokenService
{
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

        /** @var RefreshToken|null $existing */
        $existing = RefreshToken::query()->where('token_hash', $hash)->first();

        if ($existing === null) {
            throw new InvalidRefreshTokenException;
        }

        if ($existing->isRevoked()) {
            // Reuse of an already-rotated (or already-logged-out) token.
            // Treat as compromise: kill every session this user holds.
            $this->revokeAllForUser($existing->user);

            throw new InvalidRefreshTokenException(
                'Refresh token was already used. All sessions have been revoked as a precaution.'
            );
        }

        if ($existing->isExpired()) {
            throw new InvalidRefreshTokenException;
        }

        return DB::transaction(function () use ($existing, $request) {
            ['plain' => $plain, 'model' => $new] = $this->create($existing->user, $request);

            $existing->forceFill([
                'revoked_at' => now(),
                'replaced_by_id' => $new->id,
            ])->save();

            return ['plain' => $plain, 'model' => $new, 'user' => $existing->user];
        });
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
