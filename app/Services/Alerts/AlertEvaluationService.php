<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * S5-01 / FR-20: the three rule-based proactive alerts, evaluated once
 * per client per scheduled run (see `EvaluateAlerts`).
 *
 * `no_log` and `calories_exceeded` describe an ongoing CONDITION: each
 * run either opens one alert (if none is already open) or resolves the
 * open one (the moment the condition stops holding) — never both, never
 * a second open alert for the same still-true condition. `milestone` is
 * a one-shot event with no "still true" state to resolve; it is instead
 * debounced so a sustained trend doesn't re-fire on every run.
 */
class AlertEvaluationService
{
    public function evaluate(Subscriber $subscriber): void
    {
        $this->evaluateNoLog($subscriber);
        $this->evaluateCaloriesExceeded($subscriber);
        $this->evaluateMilestone($subscriber);
    }

    private function evaluateNoLog(Subscriber $subscriber): void
    {
        $staleBefore = CarbonImmutable::now()->subDays((int) config('adherence.late_after_days'));
        $isStale = $subscriber->last_logged_at === null || $subscriber->last_logged_at->lt($staleBefore);

        if ($isStale) {
            $this->openIfNotAlready($subscriber, Alert::TYPE_NO_LOG, sprintf(
                'No meal logged in %d+ days.',
                (int) config('adherence.late_after_days'),
            ));
        } else {
            $this->resolveOpen($subscriber, Alert::TYPE_NO_LOG);
        }
    }

    /**
     * Evaluated over the last N FULL calendar days ending YESTERDAY, today
     * deliberately excluded. Today's log total is still accumulating —
     * scoring a half-finished day as "under target" the moment the job
     * runs would be an artifact of when the job runs, not a real signal.
     * A day with no logs at all breaks the streak rather than counting as
     * "not exceeded": nothing was actually measured that day.
     */
    private function evaluateCaloriesExceeded(Subscriber $subscriber): void
    {
        $target = $subscriber->healthProfile?->daily_calorie_needs;

        if ($target === null) {
            return;
        }

        $streakDays = (int) config('alerts.calorie_exceeded_streak_days');
        $today = CarbonImmutable::now()->startOfDay();

        $dailyTotals = DB::table('meal_logs')
            ->join('foods', 'foods.id', '=', 'meal_logs.food_id')
            ->where('meal_logs.subscriber_id', $subscriber->id)
            ->where('meal_logs.logged_at', '<', $today)
            // A small buffer beyond the streak window so a gap just past
            // it doesn't need a second query to confirm the streak ends there.
            ->where('meal_logs.logged_at', '>=', $today->subDays($streakDays + 4))
            ->selectRaw('DATE(meal_logs.logged_at) as log_date, SUM(meal_logs.quantity_grams / 100 * foods.calories_per_100g) as total_calories')
            ->groupBy('log_date')
            ->pluck('total_calories', 'log_date');

        $streakHolds = true;
        for ($i = 1; $i <= $streakDays; $i++) {
            $date = $today->subDays($i)->toDateString();
            if (! isset($dailyTotals[$date]) || (float) $dailyTotals[$date] <= $target) {
                $streakHolds = false;
                break;
            }
        }

        if ($streakHolds) {
            $this->openIfNotAlready($subscriber, Alert::TYPE_CALORIES_EXCEEDED, sprintf(
                'Daily calorie target exceeded for %d consecutive days.',
                $streakDays,
            ));
        } else {
            $this->resolveOpen($subscriber, Alert::TYPE_CALORIES_EXCEEDED);
        }
    }

    /**
     * No target weight is stored anywhere in the schema, so "progress
     * toward the goal" is measured as movement, not distance to a number:
     * the change between the earliest and latest reading in the window,
     * signed by the goal's direction. weight_maintenance and
     * health_monitoring have no stored band to measure against and are
     * skipped outright — see config/alerts.php for why that is a real
     * gap, not a silently invented rule.
     */
    private function evaluateMilestone(Subscriber $subscriber): void
    {
        if (! in_array($subscriber->goal, ['weight_loss', 'weight_gain'], true)) {
            return;
        }

        $windowStart = CarbonImmutable::now()->subDays((int) config('alerts.milestone_window_days'));

        $readings = $subscriber->bodyCompositionReadings()
            ->where('recorded_at', '>=', $windowStart->toDateString())
            ->reorder('recorded_at')
            ->get(['recorded_at', 'weight_kg']);

        if ($readings->count() < 2) {
            return;
        }

        $first = (float) $readings->first()->weight_kg;
        $last = (float) $readings->last()->weight_kg;
        $threshold = (float) config('alerts.milestone_weight_change_kg');

        $achieved = $subscriber->goal === 'weight_loss'
            ? ($first - $last) >= $threshold
            : ($last - $first) >= $threshold;

        if (! $achieved) {
            return;
        }

        // One-shot, debounced rather than open/resolve: a sustained trend
        // would otherwise re-qualify on every single run. Skip if a
        // milestone was already raised within this same window.
        $alreadyRaised = $subscriber->alerts()
            ->ofType(Alert::TYPE_MILESTONE)
            ->where('created_at', '>=', $windowStart)
            ->exists();

        if ($alreadyRaised) {
            return;
        }

        $subscriber->alerts()->create([
            'type' => Alert::TYPE_MILESTONE,
            'message' => sprintf(
                'Milestone: %.1fkg %s over the last %d days.',
                abs($last - $first),
                $subscriber->goal === 'weight_loss' ? 'lost' : 'gained',
                (int) config('alerts.milestone_window_days'),
            ),
        ]);
    }

    private function openIfNotAlready(Subscriber $subscriber, string $type, string $message): void
    {
        $alreadyOpen = $subscriber->alerts()->ofType($type)->open()->exists();

        if (! $alreadyOpen) {
            $subscriber->alerts()->create(['type' => $type, 'message' => $message]);
        }
    }

    private function resolveOpen(Subscriber $subscriber, string $type): void
    {
        $subscriber->alerts()->ofType($type)->open()->update(['resolved_at' => now()]);
    }
}
