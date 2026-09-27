<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\InvalidGoogleTokenException;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterNutritionistRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\JwtService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * F-1 / FR-01, FR-04, FR-05: nutritionist registration, login (with
 * lockout), and JWT access/refresh issuance. Client login uses the same
 * endpoints once Sprint 2's invite-link activation creates the account —
 * this controller doesn't distinguish by role.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly RefreshTokenService $refreshTokens,
        private readonly GoogleAuthService $google,
    ) {}

    /**
     * Register a new nutritionist account.
     */
    public function register(RegisterNutritionistRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => $request->string('password'),
        ]);

        $user->assignRole('nutritionist');

        return $this->tokenResponse($user, $request, 201);
    }

    /**
     * Authenticate with (email or phone) + password — a nutritionist has
     * an email, a client (added by name + phone only, no email) logs in
     * by phone instead. Locks the account for `jwt.lockout_minutes` after
     * `jwt.max_login_attempts` consecutive failures (FR-04).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $request->filled('email')
            ? User::where('email', $request->string('email'))->first()
            : User::where('phone', $request->string('phone'))->first();

        // Same generic error whether the email doesn't exist or the
        // password is wrong — don't leak which one it was.
        if ($user === null) {
            return $this->invalidCredentialsResponse();
        }

        if ($user->isLocked()) {
            throw new AccountLockedException($user->locked_until);
        }

        if (! Hash::check($request->string('password'), $user->password)) {
            $user->registerFailedLogin();

            return $this->invalidCredentialsResponse();
        }

        $user->resetFailedLogins();

        return $this->tokenResponse($user, $request, 200);
    }

    /**
     * "Continue with Google": sign in, or sign up as a nutritionist, with a
     * Google access token the browser obtained. 503 when the server has no
     * GOOGLE_CLIENT_ID (the frontend hides the button in that case too),
     * 401 when Google won't vouch for the token. Locked accounts stay
     * locked here as well — Google is another door into the same account.
     */
    public function google(GoogleAuthRequest $request): JsonResponse
    {
        if (! $this->google->isConfigured()) {
            return response()->json(['message' => 'Google sign-in is not available.'], 503);
        }

        try {
            $user = $this->google->authenticate($request->string('access_token'));
        } catch (InvalidGoogleTokenException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        if ($user->isLocked()) {
            throw new AccountLockedException($user->locked_until);
        }

        $user->resetFailedLogins();

        return $this->tokenResponse($user, $request, 200);
    }

    /**
     * Send a password-reset link. The answer is the same 200 whether or
     * not the address has an account, so this can't enumerate users; the
     * broker's own per-address throttle (60 s) also hides behind it.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $locale = $request->string('locale', 'ar')->toString();

        Password::broker()->sendResetLink(
            ['email' => $request->string('email')->lower()->toString()],
            function (User $user, string $token) use ($locale): void {
                $user->notify(new ResetPasswordNotification($token, $locale));
            },
        );

        return response()->json(['message' => 'If an account exists for that e-mail, a reset link has been sent.']);
    }

    /**
     * Set a new password from a reset link. On success every refresh
     * token the user holds is revoked, so a session an attacker may have
     * opened before the reset dies with it.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => $request->string('email')->lower()->toString(),
                'token' => $request->string('token')->toString(),
                'password' => $request->string('password')->toString(),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();

                $this->refreshTokens->revokeAllForUser($user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can sign in now.']);
    }

    /**
     * Exchange a valid refresh token for a new access + refresh token
     * pair. The presented refresh token is revoked and replaced
     * (rotation) — reusing it afterwards revokes every session the user
     * holds (see RefreshTokenService::rotate()).
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $result = $this->refreshTokens->rotate($request->string('refresh_token'), $request);
        } catch (InvalidRefreshTokenException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        return response()->json([
            'access_token' => $this->jwt->issueAccessToken($result['user']),
            'refresh_token' => $result['plain'],
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    /**
     * Revoke the current session's refresh token. The access token
     * itself is stateless and simply expires on its own short TTL.
     */
    public function logout(RefreshTokenRequest $request): JsonResponse
    {
        $token = $this->refreshTokens->findValidByPlainToken($request->string('refresh_token'));

        if ($token !== null) {
            $this->refreshTokens->revoke($token);
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * The authenticated user — proves the `jwt` middleware is wired
     * correctly end to end.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(User $user, Request $request, int $status): JsonResponse
    {
        $refresh = $this->refreshTokens->issue($user, $request);

        return response()->json([
            'user' => new UserResource($user),
            'access_token' => $this->jwt->issueAccessToken($user),
            'refresh_token' => $refresh['plain'],
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ], $status);
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json(['message' => 'These credentials do not match our records.'], 401);
    }
}
