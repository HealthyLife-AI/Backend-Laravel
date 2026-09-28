<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Forgot password" e-mail. The link points at the web dashboard (not at
 * this API), on the locale the person was using when they asked, so the
 * reset screen opens in their language. The token is single-use and
 * expires per `config('auth.passwords.users.expire')` minutes.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * `$resetLocale`, not `$locale`: the base Notification already owns a
     * public `$locale` (the mail locale, set via ->locale()); this one is
     * the language the reset screen should open in — kept in sync below.
     */
    public function __construct(
        public readonly string $token,
        public readonly string $resetLocale = 'ar',
    ) {
        $this->locale($resetLocale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function resetUrl(object $notifiable): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/'.$this->resetLocale.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        if ($this->resetLocale === 'ar') {
            return (new MailMessage)
                ->subject('إعادة تعيين كلمة المرور — HealthyLife AI')
                ->greeting('مرحبًا '.$notifiable->name.'،')
                ->line('وصلنا طلب لإعادة تعيين كلمة مرور حسابك.')
                ->action('إعادة تعيين كلمة المرور', $url)
                ->line("ينتهي هذا الرابط خلال {$minutes} دقيقة.")
                ->line('إذا لم تطلب ذلك، تجاهل هذه الرسالة وستبقى كلمة مرورك كما هي.')
                ->salutation('فريق HealthyLife AI');
        }

        return (new MailMessage)
            ->subject('Reset your password — HealthyLife AI')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('We received a request to reset the password for your account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, ignore this e-mail and your password stays the same.')
            ->salutation('The HealthyLife AI team');
    }
}
