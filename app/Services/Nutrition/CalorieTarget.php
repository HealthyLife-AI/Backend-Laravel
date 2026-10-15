<?php

namespace App\Services\Nutrition;

use App\Models\MealPlan;
use App\Models\Subscriber;

/**
 * Item C: the one answer to "how many calories is this client on?".
 *
 * `health_profiles.daily_calorie_needs` is the Mifflin-St Jeor figure
 * and keeps being recomputed whenever the profile is saved — it is the
 * SYSTEM SUGGESTION. Once the nutritionist approves a number on the
 * active plan, hers is the target: the formula does not know about the
 * pregnancy, the thyroid result or the Ramadan schedule she does.
 *
 * Everything that compares a client against a target — the
 * calorie-exceeded alert, the AI draft, the designer's ring — reads it
 * from here, so they cannot disagree about which number is in force.
 */
class CalorieTarget
{
    /**
     * The approved figure on the client's ACTIVE plan, else the formula.
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

    /** The formula's figure on its own, for showing beside hers as "اقتراح النظام". */
    public static function suggestion(Subscriber $subscriber): ?int
    {
        $needs = $subscriber->healthProfile?->daily_calorie_needs;

        return $needs === null ? null : (int) $needs;
    }
}
