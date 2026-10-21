<?php

namespace App\Services\Alerts;

use App\Models\Subscriber;
use App\Models\SubscriberAlertSetting;

/**
 * Pilot feedback 2, B: the thresholds one patient's alerts are judged by -
 * the nutritionist's override for that patient where she set one, else the
 * global config default. The single place either is read for an alert.
 *
 * Config is read at call time, never cached on this object, so changing a
 * global default still reaches every patient who has no override of their
 * own.
 *
 * Deliberately NOT used by: adherence scoring (percent and the decline
 * rule), LogWindow (patient_app.late_after_days: how long the PATIENT may
 * back-date an entry) and the 20:00 reminder. See the call sites listed in
 * the pilot-feedback-2 report.
 */
class AlertThresholds
{
    /** @return array<string, int|float> the global defaults, keyed by override field */
    public function defaults(): array
    {
        return [
            'no_log_days' => (int) config('adherence.late_after_days'),
            'calorie_exceeded_streak_days' => (int) config('alerts.calorie_exceeded_streak_days'),
            'milestone_weight_change_kg' => (float) config('alerts.milestone_weight_change_kg'),
            'milestone_window_days' => (int) config('alerts.milestone_window_days'),
        ];
    }

    /** @return array<string, int|float|null> this patient's own values; null = not overridden */
    public function overrides(Subscriber $subscriber): array
    {
        $row = $this->row($subscriber);

        return collect(array_keys(SubscriberAlertSetting::FIELDS))
            ->mapWithKeys(fn (string $field) => [$field => $row?->{$field}])
            ->all();
    }

    /** @return array<string, int|float> what applies to this patient: the override, else the default */
    public function for(Subscriber $subscriber): array
    {
        $defaults = $this->defaults();

        return collect($this->overrides($subscriber))
            ->mapWithKeys(fn ($value, string $field) => [$field => $value ?? $defaults[$field]])
            ->all();
    }

    public function noLogDays(Subscriber $subscriber): int
    {
        return (int) $this->for($subscriber)['no_log_days'];
    }

    private function row(Subscriber $subscriber): ?SubscriberAlertSetting
    {
        // `relationLoaded` first: the dashboard's list and the daily job
        // walk many patients, and an eager-loaded row must not be re-queried.
        return $subscriber->relationLoaded('alertSettings')
            ? $subscriber->alertSettings
            : SubscriberAlertSetting::query()->where('subscriber_id', $subscriber->id)->first();
    }
}
