<?php

namespace App\Http\Resources;

use App\Models\MealItem;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MealItem
 */
class MealItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $macros = app(MealPlanCalculatorService::class)->itemMacros($this->resource);

        return [
            'id' => $this->id,
            'food' => new FoodResource($this->whenLoaded('food', fn () => $this->food)),
            'quantity_grams' => (float) $this->quantity_grams,
            'macros' => $macros,
            // BR-4: every planned item ships its permitted alternatives
            // inline — the client app (S3-04) and the plan designer both
            // need "this item, here's what it can be swapped for" as one
            // unit, not a second round-trip.
            'alternatives' => MealItemResource::collection($this->whenLoaded('alternatives')),
        ];
    }
}
