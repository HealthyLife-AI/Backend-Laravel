<?php

namespace App\Http\Controllers\Api\Clients;

use App\Exceptions\Auth\InvalidInviteTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ActivateInviteRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\JwtService;
use App\Services\Auth\RefreshTokenService;
use App\Services\Clients\ClientInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * FR-03: public (unauthenticated) endpoint — the invite token itself is
 * the credential. Reuses Sprint 1's JwtService/RefreshTokenService so a
 * freshly activated client is immediately logged in (Milestones US-02
 * AC: "the client sets a password ... and gains access"), exactly like
 * register/login already do.
 */
class ClientInviteController extends Controller
{
    public function __construct(
        private readonly ClientInviteService $invites,
        private readonly JwtService $jwt,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function activate(string $token, ActivateInviteRequest $request): JsonResponse
    {
        try {
            $invite = $this->invites->consume($token);
        } catch (InvalidInviteTokenException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $subscriber = $invite->subscriber;
        $user = $subscriber->user;

        $user->forceFill(['password' => Hash::make($request->string('password'))])->save();
        $subscriber->forceFill(['status' => 'active'])->save();

        $refresh = $this->refreshTokens->issue($user, $request);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $this->jwt->issueAccessToken($user),
            'refresh_token' => $refresh['plain'],
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }
}
