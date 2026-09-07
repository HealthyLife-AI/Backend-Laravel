<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\Auth\JwtService;

/**
 * The API authenticates via the custom `jwt` middleware (Bearer access
 * token), not a Laravel session guard — `actingAs()` doesn't apply here.
 */
trait AuthenticatesForApi
{
    /** @return array{Authorization: string} */
    protected function bearerFor(User $user): array
    {
        $token = app(JwtService::class)->issueAccessToken($user);

        return ['Authorization' => "Bearer {$token}"];
    }
}
