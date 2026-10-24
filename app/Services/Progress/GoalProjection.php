<?php

namespace App\Services\Progress;

use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use App\Services\Nutrition\CalorieTarget;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;

/**
 * Phase 2, C: when could this patient reach their target weight? An estimate
 * (see config/projection.php), never a promise.
 *
 * Needs a weight-loss or weight-gain goal with a target weight, a current
 * weight, and a calorie target; otherwise, or when the target is already
 * reached, there is nothing to project and the answer is null.
 */
class GoalProjection
{
    public function __construct(private readonly AdherenceService $adherence) {}

    /**
     * The full picture, for the nutritionist: the patient's payload plus the
     * inputs it was computed from.
     *
     * @return array<string, mixed>|null
     */
    public function for(Subscriber $subscriber): ?array
    {
        $goal = $subscriber->patientGoal;
        $profile = $subscriber->healthProfile;

        if ($goal === null || ! in_array($goal->goal_type, ['weight_loss', 'weight_gain'], true) || $goal->target_weight_kg === null || $profile === null) {
            return null;
        }

        $maintenance = $profile->daily_calorie_needs === null ? null : (int) $profile->daily_calorie_needs;
        $target = CalorieTarget::effective($subscriber);
        $current = $this->currentWeight($subscriber);

        if ($maintenance === null || $target === null || $current === null) {
            return null;
        }

        $loss = $goal->goal_type === 'weight_loss';
        $targetWeight = (float) $goal->target_weight_kg;
        $remaining = $loss ? $current - $targetWeight : $targetWeight - $current;

        if ($remaining <= 0) {
            return null;
        }

        // Only a gap in the goal's own direction counts: a weight-loss goal on a
        // target at or above maintenance has no energy deficit to project from.
        $gap = $loss ? $maintenance - $target : $target - $maintenance;
        $cap = (float) config($loss ? 'projection.max_weekly_loss_kg' : 'projection.max_weekly_gain_kg');
        $weeklyFull = $gap > 0 ? min($gap * 7 / (float) config('projection.kcal_per_kg'), $cap) : 0.0;

        $adherencePercent = $this->adherencePercent($subscriber);
        $levels = (array) config('projection.scenarios');

        $scenarios = [];
        foreach ($levels as $level => $percent) {
            $scenarios[] = ['level' => $level] + $this->scenario($remaining, $weeklyFull * $percent / 100);
        }

        return [
            'remaining_kg' => round($remaining, 1),
            'direction' => $loss ? 'loss' : 'gain',
            'scenarios' => $scenarios,
            'current_level' => $this->closestLevel($levels, $adherencePercent),
            'observed_weekly_change_kg' => $this->observedWeeklyChange($subscriber),
            'disclaimer_ar' => (string) config('projection.disclaimer_ar'),
            // Nutritionist only: what it was computed from.
            'inputs' => [
                'current_weight_kg' => round($current, 1),
                'target_weight_kg' => round($targetWeight, 1),
                'maintenance_kcal' => $maintenance,
                'target_kcal' => $target,
                'adherence_percent' => $adherencePercent,
                'weekly_change_full_kg' => round($weeklyFull, 2),
            ],
        ];
    }

    /**
     * The patient's copy: only when the nutritionist switched it on, and with
     * no calorie figure anywhere in it.
     *
     * @return array<string, mixed>|null
     */
    public function forPatient(Subscriber $subscriber): ?array
    {
        if (! $subscriber->show_goal_projection) {
            return null;
        }

        $projection = $this->for($subscriber);

        if ($projection === null) {
            return null;
        }

        unset($projection['inputs']);

        return $projection;
    }

    /** @return array{weeks: int|null, estimated_date: string|null, reachable: bool} */
    private function scenario(float $remaining, float $weeklyChange): array
    {
        if ($weeklyChange < (float) config('projection.min_weekly_change_kg')) {
            return ['weeks' => null, 'estimated_date' => null, 'reachable' => false];
        }

        $weeks = (int) ceil($remaining / $weeklyChange);

        return ['weeks' => $weeks, 'estimated_date' => ClinicDay::today()->addWeeks($weeks)->toDateString(), 'reachable' => true];
    }

    /**
     * The scenario whose adherence is nearest the patient's own. Halfway
     * between two, the more cautious (lower) one: it errs toward "longer".
     *
     * @param  array<string, int|float>  $levels
     */
    private function closestLevel(array $levels, ?float $adherencePercent): ?string
    {
        if ($adherencePercent === null) {
            return null;
        }

        asort($levels);
        $best = null;
        $bestDistance = INF;

        foreach ($levels as $level => $percent) {
            $distance = abs($percent - $adherencePercent);

            if ($distance < $bestDistance) {
                $best = $level;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function adherencePercent(Subscriber $subscriber): ?float
    {
        $today = ClinicDay::today();
        $from = $today->subDays((int) config('projection.adherence_window_days') - 1)->toDateString();

        $percent = $this->adherence->summary($subscriber, $from, $today->toDateString())['adherence_percent'];

        return $percent === null ? null : (float) $percent;
    }

    /** The latest recorded weight, else the one on the health profile. */
    private function currentWeight(Subscriber $subscriber): ?float
    {
        $latest = $subscriber->bodyCompositionReadings()->reorder()->whereNotNull('weight_kg')
            ->orderByDesc('recorded_at')->orderByDesc('id')->value('weight_kg');

        $weight = $latest ?? $subscriber->healthProfile?->weight_kg;

        return $weight === null ? null : (float) $weight;
    }

    /**
     * What the scale actually did: a least-squares line through the weights of
     * the last few weeks, in kg per week (negative = losing). Only reported
     * when there are enough readings over enough days; never used to project.
     */
    private function observedWeeklyChange(Subscriber $subscriber): ?float
    {
        $cfg = (array) config('projection.trend');
        $since = ClinicDay::today()->subDays((int) $cfg['window_days'])->toDateString();

        $points = $subscriber->bodyCompositionReadings()->reorder()->whereNotNull('weight_kg')
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')->orderBy('id')->get(['recorded_at', 'weight_kg']);

        if ($points->count() < (int) $cfg['min_readings']) {
            return null;
        }

        $first = CarbonImmutable::parse($points->first()->recorded_at->toDateString());
        $xs = $points->map(fn ($p) => (float) $first->diffInDays(CarbonImmutable::parse($p->recorded_at->toDateString())));
        $ys = $points->map(fn ($p) => (float) $p->weight_kg);

        if ($xs->max() < (int) $cfg['min_span_days']) {
            return null;
        }

        $n = $points->count();
        $meanX = $xs->sum() / $n;
        $meanY = $ys->sum() / $n;
        $denominator = $xs->map(fn ($x) => ($x - $meanX) ** 2)->sum();

        if ($denominator == 0.0) {
            return null;
        }

        $slopePerDay = $xs->zip($ys)->map(fn ($pair) => ($pair[0] - $meanX) * ($pair[1] - $meanY))->sum() / $denominator;

        return round($slopePerDay * 7, 2);
    }
}
