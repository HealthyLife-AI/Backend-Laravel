<?php

namespace App\Services\Alerts;

use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;

/**
 * B13: from when a patient is EXPECTED to log meals — the later of the day
 * they first signed in (activated_at, else created_at for patients active
 * before that column) and the day their current plan took effect
 * (start_date, else activated_at, else created_at: the same date adherence
 * uses). Null = nothing to log against yet: no active plan, or one that
 * starts in the future. Used by the no-log alert and the 20:00 reminder.
 *
 * Accepted side effect: activating a new plan restarts the clock, so a
 * lapsed patient's no-log alert goes quiet for up to late_after_days days.
 */
class LoggingClock
{
    public function start(Subscriber $subscriber): ?CarbonImmutable
    {
        $plan = MealPlan::query()->where('subscriber_id', $subscriber->id)->where('status', 'active')->latest('activated_at')->first();

        if ($plan === null) {
            return null;
        }

        $planStart = ClinicDay::date($plan->start_date?->toDateString() ?? ClinicDay::ymdOf($plan->activated_at ?? $plan->created_at));

        if ($planStart->isFuture()) {
            return null;
        }

        $joined = CarbonImmutable::parse($subscriber->activated_at ?? $subscriber->created_at);

        return $joined->max($planStart);
    }

    /** No log since the clock started (or since the last log, whichever is newer) for late_after_days. */
    public function isStale(Subscriber $subscriber): bool
    {
        $start = $this->start($subscriber);

        if ($start === null) {
            return false;
        }

        $since = $subscriber->last_logged_at !== null ? CarbonImmutable::parse($subscriber->last_logged_at)->max($start) : $start;

        return $since->lessThan(CarbonImmutable::now()->subDays((int) config('adherence.late_after_days')));
    }
}
