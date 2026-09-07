<?php

namespace App\Services\Auth;

use App\Models\User;
use Firebase\JWT\ExpiredException as FirebaseExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use stdClass;
use UnexpectedValueException;

/**
 * Issues and verifies short-lived JWT access tokens (FR-01, FR-05).
 *
 * This deliberately does not manage refresh tokens — those are opaque,
 * stored, and rotated by RefreshTokenService. The access token is
 * stateless on purpose: any request carrying a valid, unexpired signature
 * is authenticated without a database round-trip.
 */
class JwtService
{
    /**
     * Issue a signed access token for the given user.
     *
     * The `role` claim is the user's primary Spatie role and
     * `nutritionist_id` is carried for client-role users, so the API
     * layer and, later, the global data-isolation scope (App\Models\
     * Scopes\NutritionistScope) can trust the token without an extra
     * query on every request.
     */
    public function issueAccessToken(User $user): string
    {
        $now = now();

        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'jti' => (string) Str::uuid(),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $now->copy()->addMinutes(config('jwt.ttl'))->timestamp,
            'role' => $user->getRoleNames()->first(),
            'nutritionist_id' => $user->nutritionist_id,
        ];

        return JWT::encode($payload, $this->secret(), config('jwt.algo'));
    }

    /**
     * Decode and verify an access token. Returns the payload, or null if
     * the token is missing, malformed, expired, or has an invalid
     * signature — callers don't need to know which.
     */
    public function decodeAccessToken(string $token): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($this->secret(), config('jwt.algo')));
        } catch (FirebaseExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (blank($secret)) {
            throw new \RuntimeException('JWT_SECRET is not configured. Set it in .env (see .env.example).');
        }

        return $secret;
    }
}
