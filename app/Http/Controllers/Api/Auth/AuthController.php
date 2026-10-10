<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\FollowUpEndedException;
use App\Exceptions\Auth\InvalidGoogleTokenException;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleAuthRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\RegisterNutritionistRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\JwtService;
use App\Services\Auth\RefreshTokenService;
use App\Services\Clients\FollowUpService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * F-1 / FR-01, FR-04, FR-05: nutritionist registration, login (with
 * lockout), and JWT access/refresh issuance. Patients sign in through the
 * same login with the username and generated password their nutritionist
 * sent them (Part A).
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
     * Patients sign in with username + password (Part A), nutritionists
     * with email + password. phone + password still works until the
     * patient app ships username login. Locks an account for
     * `jwt.lockout_minutes` after `jwt.max_login_attempts` consecutive
     * failures (FR-04). A pending patient becomes active at their first
     * successful sign-in.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if ($request->filled('username')) {
            $candidates = User::where('username', $request->input('username'))->get();
        } elseif ($request->filled('email')) {
            $candidates = User::where('email', $request->string('email'))->get();
        } else {
            // TEMPORARY: remove after patient app ships username login.
            // A phone is unique per nutritionist only, so several accounts can
            // share it: the password is checked against each of them.
            // Numbers saved before A5 may be stored as typed, so both forms are tried.
            $phone = $request->string('phone')->toString();
            $candidates = User::whereIn('phone', array_unique([$phone, Phone::clean($phone)]))->get();
        }

        $user = $this->authenticate($candidates, $request->string('password')->toString());

        // Same generic error whether the account doesn't exist or the
        // password is wrong — don't leak which one it was.
        if ($user === null) {
            return $this->invalidCredentialsResponse();
        }

        // Checked only after the password matched, so the response never
        // reveals to a stranger that an account exists or is archived.
        if ($user->isFollowUpEnded()) {
            throw new FollowUpEndedException(app(FollowUpService::class)->endedDetails($user));
        }

        // "pending" means "hasn't signed in yet": the first sign-in activates.
        Subscriber::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'active', 'activated_at' => now()]);

        return $this->tokenResponse($user, $request, 200);
    }

    /**
     * The account among $candidates whose password matches, or null. A
     * locked account never signs in: when every candidate is locked the
     * answer is 423 (as for a single account); otherwise a wrong password
     * counts as a failed attempt on every unlocked candidate — knowing a
     * shared number is not a way around the lockout.
     *
     * @param  Collection<int, User>  $candidates
     */
    private function authenticate(Collection $candidates, string $password): ?User
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $unlocked = $candidates->reject(fn (User $user) => $user->isLocked());

        if ($unlocked->isEmpty()) {
            throw new AccountLockedException($candidates->min('locked_until'));
        }

        $match = $unlocked->first(fn (User $user) => Hash::check($password, $user->password));

        if ($match === null) {
            $unlocked->each(fn (User $user) => $user->registerFailedLogin());

            return null;
        }

        $match->resetFailedLogins();

        return $match;
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

        // Google matches an existing account by e-mail, whatever its role,
        // so an archived patient is refused here exactly as in login().
        if ($user->isFollowUpEnded()) {
            throw new FollowUpEndedException(app(FollowUpService::class)->endedDetails($user));
        }

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

                $this->refreshTokens->endAllSessions($user);
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

    /**
     * Change one's own password (current password required). Every session is
     * ended, including this one's tokens, and this device gets a fresh pair
     * in the response so it stays signed in.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // The patient chose their own password: the generated one is gone.
        $user->forceFill(['password' => $request->string('password')->toString(), 'password_is_temporary' => false])->save();
        $this->refreshTokens->endAllSessions($user);

        return $this->tokenResponse($user, $request, 200);
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
