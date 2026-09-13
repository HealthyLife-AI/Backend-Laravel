<?php

namespace App\Http\Resources;

use App\Models\MealLog;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MealLog
 */
class MealLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'food' => new FoodResource($this->whenLoaded('food', fn () => $this->food)),
            'quantity_grams' => (float) $this->quantity_grams,
            'macros' => app(MealPlanCalculatorService::class)->macrosFor($this->food, (float) $this->quantity_grams),
            'meal_item_id' => $this->meal_item_id,
            // BR-9 stated in the response rather than left for each
            // consumer to re-derive from meal_item_id being null — the
            // web dashboard (S4-07) and the mobile progress tab (S4-13)
            // both render this distinction, and they should not each
            // reimplement what "on-plan" means.
            'is_on_plan' => $this->meal_item_id !== null,
            'logged_at' => $this->logged_at?->toIso8601String(),
        ];
    }
}
