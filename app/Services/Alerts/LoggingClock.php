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

    /**
     * No log since the clock started (or since the last log, whichever is
     * newer) for THIS patient's no-log threshold: the nutritionist's
     * override if she set one, else adherence.late_after_days.
     *
     * Only the no-log ALERT asks this. The 20:00 reminder uses start()
     * alone, so a tighter threshold for one patient never changes when
     * anyone is nudged.
     */
    public function isStale(Subscriber $subscriber): bool
    {
        $start = $this->start($subscriber);

        if ($start === null) {
            return false;
        }

        $since = $subscriber->last_logged_at !== null ? CarbonImmutable::parse($subscriber->last_logged_at)->max($start) : $start;

        return $since->lessThan(CarbonImmutable::now()->subDays(app(AlertThresholds::class)->noLogDays($subscriber)));
    }
}
