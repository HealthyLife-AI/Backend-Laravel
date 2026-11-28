<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm your e-mail" for a nutritionist who signed up (audit BE-17). The
 * link points at the web dashboard, in the language they signed up with, and
 * the dashboard page sends its query to POST /auth/email/verify.
 */
class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly int $expires,
        public readonly string $token,
        public readonly string $verifyLocale = 'ar',
    ) {
        $this->locale($verifyLocale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function verifyUrl(object $notifiable): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.$this->verifyLocale.'/verify-email?'.http_build_query([
            'id' => $this->userId,
            'expires' => $this->expires,
            'token' => $this->token,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->verifyUrl($notifiable);
        $hours = (int) config('email_verification.link_hours');

        if ($this->verifyLocale === 'ar') {
            return (new MailMessage)
                ->subject('تأكيد بريدك الإلكتروني — HealthyLife AI')
                ->greeting('مرحبًا '.$notifiable->name.'،')
                ->line('شكرًا لتسجيلك. أكّد بريدك الإلكتروني لتتمكن من إضافة المرضى واستخدام المساعد الذكي.')
                ->action('تأكيد البريد الإلكتروني', $url)
                ->line("ينتهي هذا الرابط خلال {$hours} ساعة.")
                ->line('إذا لم تُنشئ هذا الحساب، تجاهل هذه الرسالة.')
                ->salutation('فريق HealthyLife AI');
        }

        return (new MailMessage)
            ->subject('Confirm your e-mail — HealthyLife AI')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Thanks for signing up. Confirm your e-mail to add patients and use the AI assistant.')
            ->action('Confirm e-mail', $url)
            ->line("This link expires in {$hours} hours.")
            ->line('If you did not create this account, ignore this e-mail.')
            ->salutation('The HealthyLife AI team');
    }
}
