<?php

namespace App\Services\Adherence;

use App\Models\Subscriber;
use Carbon\CarbonImmutable;

/**
 * S4-03 / FR-18: plan versus actual.
 *
 * FR-18 defines the adherence rate as "the proportion of logged items that
 * correspond to the assigned plan", so the denominator is what the client
 * LOGGED — not what they were planned to eat. That distinction matters and
 * is easy to get backwards: measuring against planned items would conflate
 * two different failures, eating the wrong thing and not logging at all,
 * into one number. BR-9 already records which of the two a log is, in
 * `meal_item_id`, so this is a count over that column rather than a
 * re-derivation of what "on-plan" means.
 *
 * Not logging is still surfaced — as `late` (see `statusFor`), driven by
 * `last_logged_at`, so the two failures stay separately visible.
 */
class AdherenceService
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     total_logs: int,
     *     on_plan_logs: int,
     *     off_plan_logs: int,
     *     adherence_percent: float|null,
     * }
     */
    public function summary(Subscriber $subscriber, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->resolveWindow($from, $to);

        // One aggregate query rather than loading the logs: a client with
        // months of history would otherwise pull every row into memory to
        // count two things (NFR-01).
        $totals = $subscriber->mealLogs()
            ->loggedBetween($from, $to)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(meal_item_id) as on_plan')
            ->first();

        $total = (int) ($totals->total ?? 0);
        $onPlan = (int) ($totals->on_plan ?? 0);

        return [
            'from' => $from,
            'to' => $to,
            'total_logs' => $total,
            'on_plan_logs' => $onPlan,
            'off_plan_logs' => $total - $onPlan,
            // Null, not 0, when nothing was logged: "0% adherent" and "no
            // data yet" are different clinical statements, and the client
            // profile screen (S4-09) has a distinct empty state for the
            // second one.
            'adherence_percent' => $total === 0 ? null : round($onPlan / $total * 100, 1),
        ];
    }

    /**
     * Recomputes `subscribers.adherence_status` and persists it — the
     * column the dashboard counts and the client list filters on, which
     * had no write path at all before Sprint 4 (see SRS §2.4.2).
     */
    public function refreshStatus(Subscriber $subscriber): string
    {
        $status = $this->statusFor($subscriber);

        $subscriber->forceFill(['adherence_status' => $status])->save();

        return $status;
    }

    private function statusFor(Subscriber $subscriber): string
    {
        $lateAfter = CarbonImmutable::now()->subDays((int) config('adherence.late_after_days'));

        // Checked before the percentage on purpose: a client who logged
        // one on-plan meal a fortnight ago is 100% "adherent" over any
        // window containing it, and calling that on-track would hide
        // exactly the client the nutritionist most needs to see.
        if ($subscriber->last_logged_at === null || $subscriber->last_logged_at->lt($lateAfter)) {
            return 'late';
        }

        $percent = $this->summary($subscriber)['adherence_percent'];

        if ($percent === null) {
            return 'late';
        }

        return $percent >= (int) config('adherence.on_track_percent')
            ? 'on_track'
            : 'needs_attention';
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
}
