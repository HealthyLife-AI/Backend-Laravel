<?php

namespace App\Services\Clients;

use App\Models\Subscriber;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;

/**
 * Part A: a patient's sign-in is a username and a password the system
 * generates. The nutritionist sends both on WhatsApp; the patient can
 * change the password in the app.
 *
 * The plain password is returned to the caller ONCE and exists nowhere
 * else: the model stores the hash ('hashed' cast), nothing logs it, and
 * the Sentry scrubber drops every field named like a password.
 */
class PatientCredentialsService
{
    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    /**
     * Nutritionist-initiated reset: a new generated password (and the
     * username, when given), every session ended, the lockout cleared and
     * any unused invite link from the old flow voided.
     *
     * @return array{username: string, password: string}
     */
    public function reset(Subscriber $subscriber, ?string $username): array
    {
        $password = TemporaryPassword::generate();

        DB::transaction(function () use ($subscriber, $username, $password) {
            /** @var User $user */
            $user = $subscriber->user()->lockForUpdate()->firstOrFail();

            $user->forceFill(array_filter([
                'username' => $username,
                'password' => $password,
                'password_is_temporary' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ], fn ($value, $key) => $key !== 'username' || $value !== null, ARRAY_FILTER_USE_BOTH))->save();

            $subscriber->invites()
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->subSecond()]);

            $this->refreshTokens->endAllSessions($user);
        });

        return ['username' => $subscriber->user()->value('username'), 'password' => $password];
    }
}
