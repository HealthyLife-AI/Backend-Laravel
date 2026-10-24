<?php

namespace App\Console\Commands\Measurements;

use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Services\Measurements\MeasurementScheduleService;
use App\Services\Notifications\NotificationService;
use App\Services\Scheduling\SelfScheduler;
use App\Support\ClinicDay;
use Illuminate\Console\Command;

/**
 * Phase 1, B4: the daily measurement job.
 *
 * - On (or after) the due date: ONE generic push to the patient per due
 *   date ("time to record your measurements"), remembered in
 *   `last_reminded_on` so a second run the same day, or a late catch-up,
 *   never sends it twice. No health detail in the text.
 * - Three days past due: an open `measurement_overdue` alert for the
 *   nutritionist; one that no longer holds (a reading was recorded, the
 *   date was moved) is resolved.
 *
 * One patient failing never stops the rest.
 */
class RemindMeasurements extends Command
{
    protected $signature = 'measurements:remind';

    protected $description = 'Push measurement reminders on the due date and raise an alert 3 days after it (Phase 1, B)';

    public function handle(NotificationService $notifications, MeasurementScheduleService $schedules): int
    {
        // Recorded up front, like the other daily jobs: never twice for one slot.
        app(SelfScheduler::class)->markRan('measurements');

        $today = ClinicDay::today()->toDateString();
        $reminded = 0;
        $alerts = 0;
        $failures = 0;

        SubscriberMeasurementSchedule::query()
            ->whereHas('subscriber', fn ($q) => $q->active())
            ->with(['subscriber.user'])
            ->orderBy('id')
            ->lazyById(200)
            ->each(function (SubscriberMeasurementSchedule $schedule) use ($notifications, $schedules, $today, &$reminded, &$alerts, &$failures): void {
                $subscriber = $schedule->subscriber;

                try {
                    if ($schedule->next_due_on->toDateString() <= $today
                        && ($schedule->last_reminded_on === null || $schedule->last_reminded_on->toDateString() < $schedule->next_due_on->toDateString())
                        && $subscriber->user !== null) {
                        $notifications->notify(
                            $subscriber->user,
                            'measurements',
                            'measurement_due',
                            [],
                            "measurement:{$subscriber->id}:{$schedule->next_due_on->toDateString()}",
                        );
                        $schedule->forceFill(['last_reminded_on' => $today])->save();
                        $reminded++;
                    }

                    $before = $subscriber->alerts()->open()->count();
                    $schedules->sync($subscriber, $schedule);
                    $alerts += max(0, $subscriber->alerts()->open()->count() - $before);
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error("Measurement reminder failed for subscriber {$subscriber->id}: {$e->getMessage()}");
                }
            });

        app(SelfScheduler::class)->recordResult('measurements', ['reminded' => $reminded, 'alerts_opened' => $alerts, 'failures' => $failures]);
        $this->info(sprintf('%d reminder(s), %d new overdue alert(s), %d failure(s).', $reminded, $alerts, $failures));

        return self::SUCCESS;
    }
}
