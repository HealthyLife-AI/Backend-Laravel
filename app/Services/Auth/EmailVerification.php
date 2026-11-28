<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;

/**
 * Audit BE-17. The link in the e-mail carries the user id, an expiry and an
 * HMAC of both plus the address, keyed with APP_KEY. Nothing is stored: a
 * changed address, a changed id or a passed expiry invalidates it.
 */
class EmailVerification
{
    public function token(User $user, int $expires): string
    {
        return hash_hmac('sha256', "verify-email|{$user->id}|{$user->email}|{$expires}", (string) config('app.key'));
    }

    public function send(User $user, string $locale = 'ar'): void
    {
        $expires = now()->addHours((int) config('email_verification.link_hours'))->timestamp;

        $user->notify(new VerifyEmailNotification($user->id, $expires, $this->token($user, $expires), $locale));
    }

    /** Marks the address verified when the link is genuine and unexpired. False otherwise. */
    public function verify(int $id, int $expires, string $token): bool
    {
        $user = User::query()->find($id);

        if ($user === null || $expires < now()->timestamp || ! hash_equals($this->token($user, $expires), $token)) {
            return false;
        }

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return true;
    }
}
