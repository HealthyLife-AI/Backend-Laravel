<?php

namespace App\Services\Foods;

use App\Models\Food;
use App\Models\FoodRegionalCost;
use App\Models\User;

/**
 * Writes a food's cost tier for ONE country. A cost is not clinical: it takes effect
 * at once, with no approval, and records who set it. Clearing (null) removes the row, so
 * the food is unknown there again (or, for 'PS', back to foods.cost_tier).
 */
class FoodRegionalCostService
{
    public function set(Food $food, string $country, ?string $tier, ?User $by): ?FoodRegionalCost
    {
        if ($tier === null) {
            FoodRegionalCost::query()->where('food_id', $food->id)->where('country', $country)->delete();

            return null;
        }

        return FoodRegionalCost::query()->updateOrCreate(
            ['food_id' => $food->id, 'country' => $country],
            ['cost_tier' => $tier, 'set_by' => $by?->id],
        );
    }
}
