<?php

namespace App\Services\Nutrition;

use App\Models\HealthProfile;

/**
 * BR-7 / FR-11: daily calorie needs via the Mifflin-St Jeor equation.
 *
 * Mifflin-St Jeor gives Basal Metabolic Rate (BMR) — energy burned at
 * total rest. The PRD/BR-7 call for "daily needs", which means Total
 * Daily Energy Expenditure (TDEE): BMR scaled by an activity multiplier.
 * The PRD doesn't ask for an activity-level field explicitly, but BMR
 * alone would understate every client's real needs by 20-90% — so
 * `health_profiles.activity_level` was added to make "daily needs"
 * actually mean daily needs. Multipliers are the standard Harris-
 * Benedict/Mifflin activity tiers used clinically, not invented here.
 */
class NutritionCalculatorService
{
    private const ACTIVITY_MULTIPLIERS = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
        'very_active' => 1.9,
    ];

    /**
     * @param  'male'|'female'  $gender
     * @param  'sedentary'|'light'|'moderate'|'active'|'very_active'  $activityLevel
     * @return int Daily calorie needs (TDEE), rounded to the nearest whole calorie.
     */
    public function calculateDailyCalorieNeeds(
        float $weightKg,
        float $heightCm,
        int $age,
        string $gender,
        string $activityLevel,
    ): int {
        $bmr = (10 * $weightKg) + (6.25 * $heightCm) - (5 * $age) + ($gender === 'male' ? 5 : -161);

        $tdee = $bmr * self::ACTIVITY_MULTIPLIERS[$activityLevel];

        return (int) round($tdee);
    }

    /** Convenience overload that reads straight off a HealthProfile. */
    public function calculateForProfile(HealthProfile $profile): int
    {
        return $this->calculateDailyCalorieNeeds(
            (float) $profile->weight_kg,
            (float) $profile->height_cm,
            $profile->age,
            $profile->gender,
            $profile->activity_level,
        );
    }
}
