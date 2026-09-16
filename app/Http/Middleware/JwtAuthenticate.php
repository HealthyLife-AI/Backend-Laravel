<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request from its `Authorization: Bearer <access token>`
 * header. Registered as the `jwt` middleware alias (bootstrap/app.php).
 *
 * Implements the (empty, marker-only) `AuthenticatesRequests` contract so
 * Laravel's middleware-priority sort (Kernel::$middlewarePriority) knows
 * to run this before `ThrottleRequests` on any route that combines both —
 * without it, a route-level `throttle:...` middleware silently ran
 * BEFORE this one regardless of the order either was listed in, since
 * `ThrottleRequests` IS in that priority list and an unrecognized custom
 * middleware is not. That left `$request->user()` null inside any
 * throttle limiter keyed by user id — caught by S6's rate-limiting work
 * (AppServiceProvider's `ai-draft` limiter), not by inspection; every
 * route here was unthrottled before that, so the bug had nothing to
 * surface it until then.
 */
class JwtAuthenticate implements AuthenticatesRequests
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $payload = $this->jwt->decodeAccessToken($token);

        if ($payload === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::find($payload->sub);

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);
        $request->attributes->set('jwt_payload', $payload);

        return $next($request);
    }
}
