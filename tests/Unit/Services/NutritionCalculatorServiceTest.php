<?php

namespace Tests\Unit\Services;

use App\Services\Nutrition\NutritionCalculatorService;
use Tests\TestCase;

/**
 * BR-7 / FR-11: Mifflin-St Jeor. Expected values below are hand-computed
 * from the published equation, not copied from the implementation —
 * BMR = 10w + 6.25h - 5a + (5 male | -161 female); TDEE = BMR × activity
 * factor.
 */
class NutritionCalculatorServiceTest extends TestCase
{
    private NutritionCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NutritionCalculatorService;
    }

    public function test_it_calculates_tdee_for_a_sedentary_male(): void
    {
        // BMR = 10*80 + 6.25*180 - 5*30 + 5 = 800 + 1125 - 150 + 5 = 1780
        // TDEE = 1780 * 1.2 = 2136
        $result = $this->service->calculateDailyCalorieNeeds(
            weightKg: 80, heightCm: 180, age: 30, gender: 'male', activityLevel: 'sedentary',
        );

        $this->assertSame(2136, $result);
    }

    public function test_it_calculates_tdee_for_a_moderately_active_female(): void
    {
        // BMR = 10*60 + 6.25*165 - 5*28 - 161 = 600 + 1031.25 - 140 - 161 = 1330.25
        // TDEE = 1330.25 * 1.55 = 2061.8875 -> rounds to 2062
        $result = $this->service->calculateDailyCalorieNeeds(
            weightKg: 60, heightCm: 165, age: 28, gender: 'female', activityLevel: 'moderate',
        );

        $this->assertSame(2062, $result);
    }

    public function test_activity_multiplier_strictly_increases_calorie_needs(): void
    {
        $tiers = ['sedentary', 'light', 'moderate', 'active', 'very_active'];
        $previous = 0;

        foreach ($tiers as $tier) {
            $needs = $this->service->calculateDailyCalorieNeeds(70, 170, 35, 'male', $tier);
            $this->assertGreaterThan($previous, $needs, "Expected {$tier} to exceed the previous tier.");
            $previous = $needs;
        }
    }

    public function test_male_and_female_differ_by_exactly_the_166_calorie_gender_constant(): void
    {
        $male = $this->service->calculateDailyCalorieNeeds(70, 170, 35, 'male', 'sedentary');
        $female = $this->service->calculateDailyCalorieNeeds(70, 170, 35, 'female', 'sedentary');

        // (5 - (-161)) * 1.2 activity factor = 166 * 1.2 = 199.2 -> 199 after rounding.
        $this->assertEqualsWithDelta(199, $male - $female, 1);
    }
}
