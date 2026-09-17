<?php

namespace App\Services\Adherence;

use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\Nutrition\MealPlanCalculatorService;
use Carbon\CarbonImmutable;

/**
 * S4-03 / FR-18, FR-30, BR-14: plan versus actual, classified by
 * DIRECTION rather than by level.
 *
 * FR-18 defines the adherence rate as "the proportion of logged items
 * that correspond to the assigned plan", so the denominator is what the
 * client LOGGED — not what they were planned to eat. That distinction is
 * easy to get backwards: measuring against planned items would conflate
 * two different failures, eating the wrong thing and not logging at all,
 * into one number. BR-9 already records which of the two a log is, in
 * `meal_item_id`, so this is a count over that column.
 *
 * The level is NOT the classifier. Both interviewed nutritionists said
 * so independently — Kholod named "a repeated lapse or a decline" as the
 * real trigger for intervention, and Rama cautioned that a percentage
 * alone guarantees no outcome because the plan works as a whole. So a
 * client steady at a modest rate raises nothing, while a client who fell
 * from 85% to 72% is surfaced despite sitting above the 70% reference.
 * The reference itself survives only as context (FR-30).
 */
class AdherenceService
{
    public function __construct(private readonly MealPlanCalculatorService $calculator) {}

    public const STATUS_STABLE = 'stable';

    public const STATUS_DECLINING = 'declining';

    public const STATUS_STOPPED = 'stopped_logging';

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     total_logs: int,
     *     on_plan_logs: int,
     *     off_plan_logs: int,
     *     adherence_percent: float|null,
     *     previous: array{from: string, to: string, adherence_percent: float|null},
     *     change_pp: float|null,
     *     status: string,
     *     reference_percent: int,
     * }
     */
    public function summary(Subscriber $subscriber, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveWindow($from, $to);
        [$previousFrom, $previousTo] = $this->precedingWindow($from, $to);

        $current = $this->rateFor($subscriber, $from, $to);
        $previous = $this->rateFor($subscriber, $previousFrom, $previousTo);

        $changePp = $current['adherence_percent'] !== null && $previous['adherence_percent'] !== null
            ? round($current['adherence_percent'] - $previous['adherence_percent'], 1)
            : null;

        return [
            'from' => $from,
            'to' => $to,
            'total_logs' => $current['total_logs'],
            'on_plan_logs' => $current['on_plan_logs'],
            'off_plan_logs' => $current['total_logs'] - $current['on_plan_logs'],
            'adherence_percent' => $current['adherence_percent'],
            'previous' => [
                'from' => $previousFrom,
                'to' => $previousTo,
                'adherence_percent' => $previous['adherence_percent'],
            ],
            'change_pp' => $changePp,
            'status' => $this->classify($subscriber, $current['adherence_percent'], $changePp),
            // FR-30: shipped with the payload so the UI can show the
            // reference beside the rate without hardcoding 70 of its own.
            'reference_percent' => (int) config('adherence.reference_percent'),
        ];
    }

    /**
     * S4-07: planned versus actually-logged calories, one row per day in
     * the window — the series behind the dashboard's plan-vs-actual bar
     * chart.
     *
     * Lives here rather than in a new service because this IS the
     * plan-versus-actual comparison this class is named for; `summary()`
     * above answers it as a single ratio, this answers it as a daily
     * series. Both read the same two sides, so splitting them across two
     * services would be the start of two definitions of "planned".
     *
     * `planned_calories` is null, never 0, when there is nothing to
     * compare against — no active plan, or a weekly plan with no meals on
     * that weekday. 0 would claim the plan prescribed no food that day,
     * which is a different (and wrong) statement, and the chart draws the
     * two cases differently.
     *
     * Calories come from `MealPlanCalculatorService` on both sides rather
     * than a SQL SUM: the plan side has to go through it anyway, and one
     * rounding implementation for both halves is the whole reason
     * `macrosFor()` was extracted in S4-01. A window is days, not months,
     * so the row count stays small.
     *
     * @return list<array{date: string, planned_calories: float|null, logged_calories: float}>
     */
    public function dailyCalories(Subscriber $subscriber, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveWindow($from, $to);

        $plannedByDate = $this->plannedCaloriesByDate($subscriber, $from, $to);
        $loggedByDate = $this->loggedCaloriesByDate($subscriber, $from, $to);

        $days = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();

            $days[] = [
                'date' => $date,
                'planned_calories' => $plannedByDate[$date] ?? null,
                'logged_calories' => round($loggedByDate[$date] ?? 0.0, 1),
            ];

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Resolved per DATE rather than per `day_index`, because the two plan
     * shapes the `meals` migration allows read the same day key for
     * different reasons: a plan whose meals all carry a null `day_index`
     * repeats daily and summarises under key 0, and so does a weekly plan
     * that happens to have Monday meals only. Deciding which one this is
     * needs the plan itself, so that decision is made once here instead
     * of being carried around as state.
     *
     * @return array<string, float> Calories keyed by `Y-m-d`; a date is
     *                              absent when nothing is planned for it.
     */
    private function plannedCaloriesByDate(Subscriber $subscriber, string $from, string $to): array
    {
        $plan = $subscriber->mealPlans()
            ->where('status', 'active')
            ->with(['meals.items.food'])
            ->first();

        if (! $plan instanceof MealPlan) {
            return [];
        }

        $byDayIndex = array_map(
            fn (array $totals) => (float) $totals['calories'],
            $this->calculator->planSummaryByDay($plan),
        );

        if ($byDayIndex === []) {
            return [];
        }

        $repeatsDaily = $plan->meals->every(fn ($meal) => $meal->day_index === null);
        $dailyTotal = $byDayIndex[array_key_first($byDayIndex)];

        $planned = [];
        // The plan only applies from the day it actually took effect.
        // Without this bound, a plan activated today claimed the client
        // had been prescribed those calories all week and graded them
        // against a plan that did not exist yet — which is worse than
        // showing nothing, because it reads as a real shortfall.
        //
        // `start_date` first: if the nutritionist named the day the plan
        // starts, that is the answer. Otherwise the day they handed it to
        // the client (`activated_at`), and only failing both — a plan
        // predating that column — the day it was drafted.
        $effectiveStart = CarbonImmutable::parse(
            $plan->start_date ?? $plan->activated_at ?? $plan->created_at
        )->startOfDay();

        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lte($end)) {
            if ($cursor->lt($effectiveStart)) {
                $cursor = $cursor->addDay();

                continue;
            }

            if ($repeatsDaily) {
                $planned[$cursor->toDateString()] = $dailyTotal;
            } else {
                // `meals.day_index` is 0 = Monday .. 6 = Sunday (that
                // migration's docblock). Carbon's own `dayOfWeek` is
                // 0 = Sunday, so this maps through isoWeekday rather than
                // passing dayOfWeek straight through and shifting the
                // whole week by one.
                $dayIndex = $cursor->isoWeekday() - 1;

                if (isset($byDayIndex[$dayIndex])) {
                    $planned[$cursor->toDateString()] = $byDayIndex[$dayIndex];
                }
            }

            $cursor = $cursor->addDay();
        }

        return $planned;
    }

    /** @return array<string, float> Calories keyed by `Y-m-d`. */
    private function loggedCaloriesByDate(Subscriber $subscriber, string $from, string $to): array
    {
        $totals = [];

        // `reorder()` for the same reason rateFor() needs it — mealLogs()
        // carries a default orderByDesc('logged_at') that MySQL rejects
        // alongside an aggregate, and that buys nothing here either.
        $logs = $subscriber->mealLogs()
            ->reorder()
            ->loggedBetween($from, $to)
            ->with('food')
            ->get();

        foreach ($logs as $log) {
            if ($log->food === null) {
                continue;
            }

            $date = $log->logged_at->toDateString();
            $calories = $this->calculator->macrosFor($log->food, (float) $log->quantity_grams)['calories'];

            $totals[$date] = ($totals[$date] ?? 0.0) + $calories;
        }

        return $totals;
    }

    /**
     * Recomputes `subscribers.adherence_status` and persists it — the
     * column the dashboard counts and the client list filters on, which
     * had no write path at all before Sprint 4 (see SRS §2.4.2).
     */
    public function refreshStatus(Subscriber $subscriber): string
    {
        $status = $this->summary($subscriber)['status'];

        $subscriber->forceFill(['adherence_status' => $status])->save();

        return $status;
    }

    /**
     * BR-14. Order matters: staleness is checked before anything about
     * the rate, because a client who logged one perfect meal a fortnight
     * ago scores 100% over any window containing it, and calling that
     * stable would hide exactly the client the nutritionist most needs
     * to see.
     */
    private function classify(Subscriber $subscriber, ?float $currentPercent, ?float $changePp): string
    {
        $staleBefore = CarbonImmutable::now()->subDays((int) config('adherence.late_after_days'));

        if ($subscriber->last_logged_at === null || $subscriber->last_logged_at->lt($staleBefore)) {
            return self::STATUS_STOPPED;
        }

        if ($currentPercent === null) {
            return self::STATUS_STOPPED;
        }

        // Nothing to compare against — a first period, or a client who
        // logged nothing in the preceding one. Absence of a prior rate is
        // not evidence of a fall, and guessing a direction from a single
        // level is the inference the interviews ruled out.
        if ($changePp === null) {
            return self::STATUS_STABLE;
        }

        return $changePp <= -(int) config('adherence.material_decline_pp')
            ? self::STATUS_DECLINING
            : self::STATUS_STABLE;
    }

    /**
     * @return array{total_logs: int, on_plan_logs: int, adherence_percent: float|null}
     */
    private function rateFor(Subscriber $subscriber, string $from, string $to): array
    {
        // One aggregate query rather than loading the logs: a client with
        // months of history would otherwise pull every row into memory to
        // count two things (NFR-01).
        //
        // `reorder()` strips `mealLogs()`'s own default `orderByDesc
        // ('logged_at')` before the aggregate select. Without it, MySQL
        // (unlike SQLite, which is lenient) rejects the query outright:
        // "Mixing of GROUP columns... is illegal if there is no GROUP BY
        // clause" — a non-aggregated ORDER BY column alongside bare
        // COUNT()s with no GROUP BY. This was invisible in the test suite
        // (SQLite-only) and broke every real call to this method on
        // MySQL. Same class of bug as ProgressController's `reorder()` on
        // `bodyCompositionReadings()` — that relation carries the same
        // kind of default ordering and was already fixed for it; this one
        // was missed.
        $totals = $subscriber->mealLogs()
            ->reorder()
            ->loggedBetween($from, $to)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(meal_item_id) as on_plan')
            ->first();

        $total = (int) ($totals->total ?? 0);
        $onPlan = (int) ($totals->on_plan ?? 0);

        return [
            'total_logs' => $total,
            'on_plan_logs' => $onPlan,
            // Null, not 0, when nothing was logged: "0% adherent" and "no
            // data yet" are different clinical statements (FR-18), and the
            // client profile screen (S4-09) has a distinct empty state for
            // the second one.
            'adherence_percent' => $total === 0 ? null : round($onPlan / $total * 100, 1),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function resolveWindow(?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null) {
            return [$from, $to];
        }

        $end = CarbonImmutable::now();
        $start = $end->subDays((int) config('adherence.default_window_days') - 1);

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * The equally-long window immediately before this one, so the two
     * rates are comparable — a 7-day period is compared against the 7
     * days before it, a 30-day period against the previous 30.
     *
     * @return array{0: string, 1: string}
     */
    private function precedingWindow(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $lengthInDays = $start->diffInDays(CarbonImmutable::parse($to)) + 1;

        return [
            $start->subDays($lengthInDays)->toDateString(),
            $start->subDay()->toDateString(),
        ];
    }
}
