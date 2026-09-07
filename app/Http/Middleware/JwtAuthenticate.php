<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request from its `Authorization: Bearer <access token>`
 * header. Registered as the `jwt` middleware alias (bootstrap/app.php).
 */
class JwtAuthenticate
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
