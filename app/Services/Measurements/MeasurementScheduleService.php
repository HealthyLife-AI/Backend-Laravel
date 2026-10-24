<?php

namespace App\Services\Measurements;

use App\Models\Alert;
use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Models\User;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Phase 1, B: when each patient is due to record body measurements.
 *
 * The nutritionist sets an interval; every recorded reading (hers, or the
 * patient's own) moves the next due date to the day of the latest reading
 * plus that interval. Days are the clinic's (ClinicDay), like everywhere
 * else a "day" decides something.
 *
 * Three days past due, the nutritionist gets a `measurement_overdue` alert;
 * it is resolved the moment a reading is recorded (or the date is moved).
 */
class MeasurementScheduleService
{
    /** Days past the due date before the nutritionist is alerted. */
    public const OVERDUE_ALERT_AFTER_DAYS = 3;

    /**
     * Sets (or replaces) the schedule. `$nextDueOn` defaults to today + interval.
     */
    public function set(Subscriber $subscriber, int $intervalDays, ?string $nextDueOn, ?User $by): SubscriberMeasurementSchedule
    {
        $today = ClinicDay::today();

        $schedule = SubscriberMeasurementSchedule::query()->updateOrCreate(
            ['subscriber_id' => $subscriber->id],
            [
                'interval_days' => $intervalDays,
                'next_due_on' => $nextDueOn !== null ? ClinicDay::date($nextDueOn)->toDateString() : $today->addDays($intervalDays)->toDateString(),
                // A new date is a new reminder.
                'last_reminded_on' => null,
                'updated_by' => $by?->id,
            ],
        );

        $this->sync($subscriber, $schedule);

        return $schedule;
    }

    public function forget(Subscriber $subscriber): void
    {
        SubscriberMeasurementSchedule::query()->where('subscriber_id', $subscriber->id)->delete();
        $this->resolveAlert($subscriber);
    }

    /**
     * A reading was recorded. Whatever its date, the alert is over; and the
     * next due date is the latest reading's day plus the interval, so a
     * reading entered late for an old date cannot pull the due date back
     * into the past.
     */
    public function recorded(Subscriber $subscriber, CarbonInterface|string $date): void
    {
        $this->resolveAlert($subscriber);

        $schedule = SubscriberMeasurementSchedule::query()->where('subscriber_id', $subscriber->id)->first();

        if ($schedule === null) {
            return;
        }

        $latest = $subscriber->bodyCompositionReadings()->reorder()->max('recorded_at');
        $recorded = CarbonImmutable::parse($date)->toDateString();
        $base = $latest !== null && CarbonImmutable::parse($latest)->toDateString() > $recorded
            ? CarbonImmutable::parse($latest)->toDateString()
            : $recorded;

        $schedule->forceFill([
            'next_due_on' => CarbonImmutable::parse($base)->addDays($schedule->interval_days)->toDateString(),
            'last_reminded_on' => null,
        ])->save();
    }

    /** Whole days past the due date (0 when not yet due). */
    public function daysOverdue(SubscriberMeasurementSchedule $schedule): int
    {
        $due = ClinicDay::date($schedule->next_due_on->toDateString());

        return max(0, (int) $due->diffInDays(ClinicDay::today(), false));
    }

    public function isOverdue(SubscriberMeasurementSchedule $schedule): bool
    {
        return $this->daysOverdue($schedule) > 0;
    }

    /** Opens the overdue alert when due 3+ days ago, resolves it otherwise. */
    public function sync(Subscriber $subscriber, SubscriberMeasurementSchedule $schedule): void
    {
        $days = $this->daysOverdue($schedule);

        if ($days >= self::OVERDUE_ALERT_AFTER_DAYS) {
            if (! $subscriber->alerts()->ofType(Alert::TYPE_MEASUREMENT_OVERDUE)->open()->exists()) {
                $subscriber->alerts()->create([
                    'type' => Alert::TYPE_MEASUREMENT_OVERDUE,
                    'severity' => Alert::SEVERITY_NORMAL,
                    'message' => sprintf('Body measurements are %d days overdue.', $days),
                ]);
            }

            return;
        }

        $this->resolveAlert($subscriber);
    }

    private function resolveAlert(Subscriber $subscriber): void
    {
        $subscriber->alerts()->ofType(Alert::TYPE_MEASUREMENT_OVERDUE)->open()->update(['resolved_at' => now()]);
    }
}
