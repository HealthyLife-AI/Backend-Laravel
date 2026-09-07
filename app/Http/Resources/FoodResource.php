<?php

namespace App\Http\Resources;

use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Food
 */
class FoodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'source' => $this->source,
            'calories_per_100g' => (float) $this->calories_per_100g,
            'protein_g_per_100g' => (float) $this->protein_g_per_100g,
            'carbs_g_per_100g' => (float) $this->carbs_g_per_100g,
            'fat_g_per_100g' => (float) $this->fat_g_per_100g,
            'fiber_g_per_100g' => $this->fiber_g_per_100g !== null ? (float) $this->fiber_g_per_100g : null,
        ];
    }
}
