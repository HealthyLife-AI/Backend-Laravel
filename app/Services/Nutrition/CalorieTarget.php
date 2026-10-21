<?php

namespace App\Services\Nutrition;

use App\Models\BodyCompositionReading;
use App\Models\MealPlan;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Support\Bmi;

/**
 * Item C: the one answer to "how many calories is this client on?".
 *
 * `health_profiles.daily_calorie_needs` is the Mifflin-St Jeor figure
 * (MAINTENANCE) and keeps being recomputed whenever the profile is saved.
 * The SYSTEM SUGGESTION is that figure adjusted for the patient's goal
 * (clinical batch 1, see suggestionDetail). Once the nutritionist approves
 * a number on the active plan, hers is the target: the formula does not
 * know about the pregnancy, the thyroid result or the Ramadan schedule she
 * does.
 *
 * Everything that compares a client against a target — the
 * calorie-exceeded alert, the AI draft, the designer's ring — reads it
 * from here, so they cannot disagree about which number is in force.
 */
class CalorieTarget
{
    /**
     * The approved figure on the client's ACTIVE plan, else the suggestion.
     * Null when neither exists (no profile yet, no plan yet).
     */
    public static function effective(Subscriber $subscriber): ?int
    {
        $approved = MealPlan::query()
            ->where('subscriber_id', $subscriber->id)
            ->where('status', 'active')
            ->value('approved_calories');

        if ($approved !== null) {
            return (int) $approved;
        }

        return self::suggestion($subscriber);
    }

    /** The goal-adjusted suggestion on its own, for showing beside hers as "اقتراح النظام". */
    public static function suggestion(Subscriber $subscriber): ?int
    {
        return self::suggestionDetail($subscriber)['suggested'];
    }

    /**
     * Clinical batch 1, B3: the suggestion and how it was reached.
     *
     * `maintenance` is the stored Mifflin figure, untouched. `adjustment`
     * is negative for a deficit. `reason` says why NO adjustment was made
     * (null when one was, or when the goal simply has none).
     *
     * @return array{maintenance: ?int, adjustment: ?int, suggested: ?int, bmi: ?float, bmi_class: ?string, reason: ?string, clamped_to_floor: ?bool}
     */
    public static function suggestionDetail(Subscriber $subscriber): array
    {
        $profile = $subscriber->healthProfile;
        $maintenance = $profile?->daily_calorie_needs === null ? null : (int) $profile->daily_calorie_needs;

        if ($maintenance === null) {
            return ['maintenance' => null, 'adjustment' => null, 'suggested' => null, 'bmi' => null, 'bmi_class' => null, 'reason' => null, 'clamped_to_floor' => null];
        }

        $age = $profile->age === null ? null : (int) $profile->age;
        $bmi = self::bmi($subscriber);
        $bmiClass = Bmi::classify($bmi, $age);
        $goal = $subscriber->patientGoal?->goal_type ?? $subscriber->goal;

        $codes = PatientCondition::query()->where('subscriber_id', $subscriber->id)->pluck('code')->all();
        $adjustment = 0;
        $reason = null;

        // A patient whose conditions are still only the old free-text list (not
        // yet mapped by health-records:map-conditions) cannot be told apart from
        // one with a listed condition. Until a nutritionist structures them, be
        // safe: no automatic adjustment, the same as a listed condition.
        $unmappedText = $codes === [] && array_filter($profile->health_conditions ?? [], fn ($c) => is_string($c) && trim($c) !== '') !== [];

        if (in_array('diabetes_type1', $codes, true)) {
            $reason = 'type1_no_deficit';
        } elseif (($age !== null && $age < 18) || $unmappedText || array_intersect($codes, config('nutrition.no_auto_adjust_conditions')) !== []) {
            $reason = 'clinical_judgement_required';
        } elseif ($goal === 'weight_loss') {
            $deficit = config('nutrition.weight_loss_deficit')[$bmiClass] ?? null;

            if ($deficit === null) {
                $reason = 'bmi_not_above_normal';
            } else {
                $adjustment = -(int) $deficit;
            }
        } elseif (in_array($goal, ['weight_gain', 'muscle_gain'], true)) {
            $surplus = config('nutrition.weight_gain_surplus');

            if ($surplus === null) {
                $reason = 'surplus_not_configured';
            } else {
                $adjustment = (int) $surplus;
            }
        }

        $suggested = $maintenance + $adjustment;
        $floor = (int) config('nutrition.calorie_floor.'.($profile->gender === 'male' ? 'male' : 'female'));
        $clamped = $suggested < $floor;

        return [
            'maintenance' => $maintenance,
            'adjustment' => $adjustment,
            'suggested' => $clamped ? $floor : $suggested,
            'bmi' => $bmi,
            'bmi_class' => $bmiClass,
            'reason' => $reason,
            'clamped_to_floor' => $clamped,
        ];
    }

    /**
     * The nutritionist's own latest BMI entry when there is one (she may
     * keep the analyser's figure), else computed from the latest weight and
     * the profile's height.
     */
    private static function bmi(Subscriber $subscriber): ?float
    {
        $readings = BodyCompositionReading::query()->where('subscriber_id', $subscriber->id)->orderByDesc('recorded_at')->orderByDesc('id');

        $entered = (clone $readings)->whereNotNull('bmi')->value('bmi');

        if ($entered !== null) {
            return (float) $entered;
        }

        $weight = (clone $readings)->whereNotNull('weight_kg')->value('weight_kg') ?? $subscriber->healthProfile?->weight_kg;

        return Bmi::compute($weight === null ? null : (float) $weight, $subscriber->healthProfile?->height_cm === null ? null : (float) $subscriber->healthProfile->height_cm);
    }
}
