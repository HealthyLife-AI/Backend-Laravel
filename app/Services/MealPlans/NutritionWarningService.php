<?php

namespace App\Services\MealPlans;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Support\ConditionCodes;

/**
 * Clinical batch 1, E4: amber, never-blocking warnings about glycemic index
 * / load (patients with any diabetes code) and sodium (hypertension), shown
 * beside the allergen report.
 *
 * ONLY foods a nutritionist has reviewed (nutrition_reviewed_at) are judged,
 * and only for the matching condition. An unreviewed food produces nothing:
 * no warning and no "unknown" noise. The app holds no GI or sodium values of
 * its own: they arrive through foods:import-nutrition-review.
 */
class NutritionWarningService
{
    /** @return list<string> the patient's structured condition codes */
    public function codes(Subscriber $subscriber): array
    {
        return PatientCondition::query()->where('subscriber_id', $subscriber->id)->pluck('code')->unique()->values()->all();
    }

    /** low / medium / high from a glycemic index. */
    public static function giCategory(int $gi): string
    {
        $cut = config('nutrition.glycemic_index');

        return $gi <= $cut['low_max'] ? 'low' : ($gi <= $cut['medium_max'] ? 'medium' : 'high');
    }

    /** low / medium / high from a glycemic load. */
    public static function glCategory(float $gl): string
    {
        $cut = config('nutrition.glycemic_load');

        return $gl <= $cut['low_max'] ? 'low' : ($gl <= $cut['medium_max'] ? 'medium' : 'high');
    }

    /**
     * Glycemic load of `$grams` of the food: GI x available carbs / 100,
     * where available carbs = (carbs - fibre, fibre null = 0) x grams / 100.
     */
    public static function glycemicLoad(Food $food, float $grams): ?float
    {
        if ($food->glycemic_index === null || $food->carbs_g_per_100g === null) {
            return null;
        }

        $available = max(0.0, (float) $food->carbs_g_per_100g - (float) ($food->fiber_g_per_100g ?? 0)) * $grams / 100;

        return round($food->glycemic_index * $available / 100, 1);
    }

    /**
     * The one glycemic judgement, shared by a plan item and the food check so
     * a row badge can never differ from the plan report: null, or type gi / gl /
     * gi_gl with the numbers. A high index of a small amount (load at or under
     * the low cut-off) is not raised; a high load is unchanged. With no
     * quantity the load is unknown, so only the index is judged.
     *
     * @return array<string, mixed>|null
     */
    private function glycemic(Food $food, ?float $grams): ?array
    {
        if ($food->glycemic_index === null) {
            return null;
        }

        $gi = (int) $food->glycemic_index;
        $gl = $grams === null ? null : self::glycemicLoad($food, $grams);
        $giHigh = self::giCategory($gi) === 'high' && ! $this->loadIsLow($food, $grams);
        $glHigh = $gl !== null && self::glCategory($gl) === 'high';

        if (! $giHigh && ! $glHigh) {
            return null;
        }

        return [
            'type' => $giHigh && $glHigh ? 'gi_gl' : ($giHigh ? 'gi' : 'gl'),
            'gi' => $gi,
            'gi_category' => self::giCategory($gi),
            'gl' => $gl,
            'gl_category' => $gl === null ? null : self::glCategory($gl),
        ];
    }

    /** True only when the quantity is known, the load can be computed, and it is at or under the low cut-off. */
    private function loadIsLow(Food $food, ?float $grams): bool
    {
        if ($grams === null) {
            return false;
        }

        $gl = self::glycemicLoad($food, $grams);

        return $gl !== null && self::glCategory($gl) === 'low';
    }

    private function reviewed(Food $food): bool
    {
        return $food->nutrition_reviewed_at !== null;
    }

    /**
     * Food-level warnings: glycemic for a patient on the glycemic watch,
     * sodium for a hypertensive one.
     *
     * `$grams` is the planned quantity when the caller knows it, and then the
     * answer is exactly what the plan report gives for an item of that size:
     * `gi`, `gl` or `gi_gl`, with both numbers. A high GI of a small amount
     * (load at or under the low cut-off) is not raised. With no quantity yet
     * only the index can be judged: the GI warning stays, since nothing says
     * the amount is small.
     *
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    public function forFood(Food $food, array $codes, ?float $grams = null): array
    {
        if (! $this->reviewed($food)) {
            return [];
        }

        $warnings = [];

        if (array_intersect($codes, ConditionCodes::GLYCEMIC_WATCH) !== []) {
            $glycemic = $this->glycemic($food, $grams);

            if ($glycemic !== null) {
                $warnings[] = $glycemic;
            }
        }

        if (in_array('hypertension', $codes, true) && $food->sodium_mg_per_100g !== null
            && (float) $food->sodium_mg_per_100g >= (float) config('nutrition.high_sodium_mg_per_100g')) {
            $warnings[] = ['type' => 'sodium', 'sodium_mg_per_100g' => (float) $food->sodium_mg_per_100g];
        }

        return $warnings;
    }

    /**
     * Warnings for every item of a plan (planned items and alternatives),
     * with the glycemic load of the actual quantity.
     *
     * @return list<array<string, mixed>>
     */
    public function forPlan(MealPlan $plan, Subscriber $subscriber, ?array $codes = null): array
    {
        $codes ??= $this->codes($subscriber);

        $diabetic = array_intersect($codes, ConditionCodes::GLYCEMIC_WATCH) !== [];
        $hypertensive = in_array('hypertension', $codes, true);

        if (! $diabetic && ! $hypertensive) {
            return [];
        }

        $plan->loadMissing('meals.items.food');
        $warnings = [];

        foreach ($plan->meals as $meal) {
            // `items` holds planned items AND alternatives (parent_item_id set).
            foreach ($meal->items as $item) {
                $warnings = array_merge($warnings, $this->forItem($item, $meal->id, $meal->day_index, $diabetic, $hypertensive));
            }
        }

        return $warnings;
    }

    /** @return list<array<string, mixed>> */
    private function forItem(MealItem $item, int $mealId, ?int $dayIndex, bool $diabetic, bool $hypertensive): array
    {
        $food = $item->food;

        if ($food === null || ! $this->reviewed($food)) {
            return [];
        }

        $base = [
            'meal_id' => $mealId,
            'day_index' => $dayIndex,
            'item_id' => $item->id,
            'parent_item_id' => $item->parent_item_id,
            'food_id' => $food->id,
            'food_name_ar' => $food->name_ar,
        ];

        $warnings = [];

        if ($diabetic && ($glycemic = $this->glycemic($food, (float) $item->quantity_grams)) !== null) {
            $warnings[] = $base + $glycemic;
        }

        if ($hypertensive && $food->sodium_mg_per_100g !== null && (float) $food->sodium_mg_per_100g >= (float) config('nutrition.high_sodium_mg_per_100g')) {
            $warnings[] = $base + ['type' => 'sodium', 'sodium_mg_per_100g' => (float) $food->sodium_mg_per_100g];
        }

        return $warnings;
    }
}
