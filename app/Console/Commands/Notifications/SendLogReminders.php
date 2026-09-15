<?php

namespace App\Console\Commands\Notifications;

use App\Models\Subscriber;
use App\Services\Notifications\FcmPushService;
use Illuminate\Console\Command;

/**
 * S5-06 / FR-22: "push notifications remind the client to log."
 *
 * Distinct from S5-01's `no_log` ALERT (which tells the NUTRITIONIST
 * after 3 quiet days) — this reminds the CLIENT, same day, using the
 * dashboard's own existing definition of "not logged today"
 * (`last_logged_at` null or before today's start — see
 * `DashboardController`), so the two features can't disagree about what
 * "hasn't logged" means.
 *
 * One client's send failing (no token, FCM unreachable, malformed
 * credentials) must not stop the rest — same principle as
 * `EvaluateAlerts` and the weekly-summary job (S5-05).
 */
class SendLogReminders extends Command
{
    protected $signature = 'notifications:send-log-reminders';

    protected $description = 'Push a reminder to every active client who has not logged today (FR-22)';

    public function handle(FcmPushService $fcm): int
    {
        if (! $fcm->isConfigured()) {
            $this->info('Firebase is not configured — skipping (rule-based fallback: no push, no error).');

            return self::SUCCESS;
        }

        $today = now()->startOfDay();

        $subscribers = Subscriber::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('last_logged_at')->orWhere('last_logged_at', '<', $today))
            ->whereHas('user', fn ($query) => $query->whereNotNull('fcm_token'))
            ->with('user')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($subscribers as $subscriber) {
            try {
                $fcm->send(
                    $subscriber->user->fcm_token,
                    'Time to log today\'s meals',
                    'You haven\'t logged anything yet today — تذكير بتسجيل وجباتك اليوم.',
                );
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->error("Reminder failed for subscriber {$subscriber->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('Sent %d reminder(s), %d failure(s), out of %d client(s) with a registered device.', $sent, $failed, $subscribers->count()));

        return self::SUCCESS;
    }
}
