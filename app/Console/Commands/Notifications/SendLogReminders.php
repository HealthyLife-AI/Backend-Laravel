<?php

namespace App\Console\Commands\Notifications;

use App\Models\Subscriber;
use App\Services\Alerts\LoggingClock;
use App\Services\Notifications\FcmPushService;
use App\Services\Notifications\NotificationService;
use App\Services\Scheduling\SelfScheduler;
use App\Support\ClinicDay;
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

    public function handle(FcmPushService $fcm, NotificationService $notifications): int
    {
        // Recorded up front: a reminder must never be pushed twice for the
        // same evening, even if this run stops partway.
        app(SelfScheduler::class)->markRan('reminders');

        if (! $fcm->isConfigured()) {
            $this->info('Firebase is not configured — skipping (rule-based fallback: no push, no error).');

            return self::SUCCESS;
        }

        $today = ClinicDay::today()->setTimezone(config('app.timezone'));
        $clock = app(LoggingClock::class);

        // lazy(), not get() — see EvaluateAlerts's identical note. ->with
        // ('user') still eager-loads per page (default 1000 rows), so this
        // stays free of the N+1 that dropping the eager load would cause.
        $total = 0;
        $sent = 0;
        $failed = 0;

        Subscriber::active()
            ->where(fn ($query) => $query->whereNull('last_logged_at')->orWhere('last_logged_at', '<', $today))
            ->whereHas('user', fn ($query) => $query->whereNotNull('fcm_token'))
            ->with('user')
            ->lazy()
            ->each(function (Subscriber $subscriber) use ($notifications, $clock, &$total, &$sent, &$failed): void {
                // B13: nothing to remind about without an active plan in force today.
                if ($clock->start($subscriber) === null) {
                    return;
                }

                $total++;

                try {
                    // Through the notification center: it lands in the inbox in the
                    // patient's language, and quiet hours / the meals toggle decide
                    // whether it is pushed.
                    $row = $notifications->notify($subscriber->user, 'meals', 'log_reminder');
                    if ($row->pushed_at !== null) {
                        $sent++;
                    } elseif ($row->push_skipped === 'failed') {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("Reminder failed for subscriber {$subscriber->id}: {$e->getMessage()}");
                }
            });

        $this->info(sprintf('Sent %d reminder(s), %d failure(s), out of %d client(s) with a registered device.', $sent, $failed, $total));

        return self::SUCCESS;
    }
}
