<?php

namespace App\Services\Nutrition;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use Illuminate\Support\Collection;

/**
 * S3-02 / FR-14: live calorie/macro totals per item, per meal, and per
 * day. Every total sums PLANNED items only (`parent_item_id` null) —
 * alternatives are substitutes the client may choose instead, not
 * additions on top of the plan, so they never inflate a total; each one
 * is still reported individually (`itemMacros`) so the UI can show what
 * it would cost if chosen instead.
 *
 * Callers pass in already-loaded models (`meal.items.food`, etc.) rather
 * than this service running its own queries — it's pure arithmetic over
 * whatever's in memory, so a single plan page load doesn't pay for
 * needing macros to be N+1 queries deep.
 */
class MealPlanCalculatorService
{
    /** @return array{calories: float, protein_g: float, carbs_g: float, fat_g: float} */
    public function itemMacros(MealItem $item): array
    {
        $food = $item->food;
        $factor = (float) $item->quantity_grams / 100;

        return [
            'calories' => round((float) $food->calories_per_100g * $factor, 1),
            'protein_g' => round((float) $food->protein_g_per_100g * $factor, 1),
            'carbs_g' => round((float) $food->carbs_g_per_100g * $factor, 1),
            'fat_g' => round((float) $food->fat_g_per_100g * $factor, 1),
        ];
    }

    /** @return array{calories: float, protein_g: float, carbs_g: float, fat_g: float} */
    public function mealMacros(Meal $meal): array
    {
        return $this->sum($meal->items->filter(fn (MealItem $item) => $item->isPlanned())
            ->map(fn (MealItem $item) => $this->itemMacros($item)));
    }

    /**
     * Totals per day for a whole plan. Meals with `day_index === null`
     * (a daily-recurring plan — see the `meals` migration) count toward
     * every day key that already has a dated meal, or key `0` alone for
     * a plan that has no day-specific meals at all.
     *
     * @return array<int, array{calories: float, protein_g: float, carbs_g: float, fat_g: float}>
     */
    public function planSummaryByDay(MealPlan $plan): array
    {
        $dayIndexes = $plan->meals->pluck('day_index')->filter(fn ($d) => $d !== null)->unique();
        $days = $dayIndexes->isEmpty() ? [0] : $dayIndexes->sort()->values()->all();

        $totals = [];
        foreach ($days as $day) {
            $mealsForDay = $plan->meals->filter(
                fn (Meal $meal) => $meal->day_index === null || $meal->day_index === $day
            );
            $totals[$day] = $this->sum($mealsForDay->map(fn (Meal $meal) => $this->mealMacros($meal)));
        }

        return $totals;
    }

    /**
     * @param  Collection<int, array{calories: float, protein_g: float, carbs_g: float, fat_g: float}>  $rows
     * @return array{calories: float, protein_g: float, carbs_g: float, fat_g: float}
     */
    private function sum($rows): array
    {
        return [
            'calories' => round($rows->sum('calories'), 1),
            'protein_g' => round($rows->sum('protein_g'), 1),
            'carbs_g' => round($rows->sum('carbs_g'), 1),
            'fat_g' => round($rows->sum('fat_g'), 1),
        ];
    }
}
