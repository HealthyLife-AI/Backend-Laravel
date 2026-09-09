<?php

namespace App\Http\Resources;

use App\Models\Meal;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Meal
 */
class MealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'day_index' => $this->day_index,
            // FR-14: planned items only — an alternative's macros are
            // reported on the item itself, never folded into this total
            // (see MealPlanCalculatorService's docblock).
            'macros' => app(MealPlanCalculatorService::class)->mealMacros($this->resource),
            'items' => MealItemResource::collection(
                $this->whenLoaded('items', fn () => $this->items->filter(fn ($item) => $item->isPlanned())->values())
            ),
        ];
    }
}
