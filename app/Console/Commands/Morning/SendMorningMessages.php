<?php

namespace App\Console\Commands\Morning;

use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Services\Notifications\NotificationService;
use App\Services\Scheduling\SelfScheduler;
use App\Services\Today\TodaySummary;
use App\Support\ClinicDay;
use Illuminate\Console\Command;

/**
 * Phase 3, B3: the daily morning push (07:30 clinic time).
 *
 * One generic push per day to each active patient who has an active plan
 * ("صباح الخير! خطة يومك جاهزة"), under the patient's `morning` notification
 * category. The text carries nothing from the health file; the content is what
 * `GET me/today` returns when the patient opens the app. A second run on the
 * same day sends nothing: the day is part of the notification's key.
 */
class SendMorningMessages extends Command
{
    protected $signature = 'morning:send';

    protected $description = 'Push the morning message to patients with an active plan, once a day (Phase 3, B)';

    public function handle(NotificationService $notifications, TodaySummary $today): int
    {
        // Recorded up front, like the other daily jobs: never twice for one slot.
        app(SelfScheduler::class)->markRan('morning');

        $date = ClinicDay::today();
        $sent = 0;
        $skipped = 0;
        $failures = 0;

        Subscriber::withoutGlobalScopes()->active()
            ->whereHas('user')
            ->whereHas('mealPlans', fn ($q) => $q->where('status', 'active'))
            ->with('user')
            ->lazyById(200)
            ->each(function (Subscriber $subscriber) use ($notifications, $today, $date, &$sent, &$skipped, &$failures): void {
                try {
                    $key = "morning:{$subscriber->id}:{$date->toDateString()}";

                    if ($today->activePlan($subscriber, $date) === null
                        || PatientNotification::query()->where('user_id', $subscriber->user_id)->where('dedupe_key', $key)->exists()) {
                        $skipped++;

                        return;
                    }

                    $notifications->notify($subscriber->user, 'morning', 'morning_message', [], $key);
                    $sent++;
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error("Morning message failed for subscriber {$subscriber->id}: {$e->getMessage()}");
                }
            });

        app(SelfScheduler::class)->recordResult('morning', ['sent' => $sent, 'skipped' => $skipped, 'failures' => $failures]);
        $this->info("{$sent} morning message(s) sent, {$skipped} skipped, {$failures} failure(s).");

        return self::SUCCESS;
    }
}
