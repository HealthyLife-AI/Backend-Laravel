<?php

namespace App\Http\Resources;

use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

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
        $isAdmin = (bool) Auth::user()?->hasRole('admin');

        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'source' => $this->source,
            'status' => $this->status,
            'calories_per_100g' => (float) $this->calories_per_100g,
            'protein_g_per_100g' => (float) $this->protein_g_per_100g,
            'carbs_g_per_100g' => (float) $this->carbs_g_per_100g,
            'fat_g_per_100g' => (float) $this->fat_g_per_100g,
            'fiber_g_per_100g' => $this->fiber_g_per_100g !== null ? (float) $this->fiber_g_per_100g : null,
            // Allergen groups (FoodTagger::GROUPS) and the shopping-list section.
            'allergens' => $this->allergens ?? [],
            // `allergens` above reads NULL (never tagged) as []. The nutritionist
            // needs to tell "tagged, none" from "unknown" before trusting a food
            // in an allergic patient's plan; the patient app is unchanged.
            $this->mergeUnless(Auth::user()?->hasRole('client'), fn () => [
                'allergens_verified' => $this->allergens !== null,
            ]),
            'shopping_section' => $this->shopping_section ?? 'other',
            // Phase 4, B: null until a nutritionist has reviewed the food; the app then shows no badge.
            // The viewer's nutritionist's country (a patient's: theirs); the admin sees foods.cost_tier itself (the PS value)
            // with the regional rows listed beside it, so editing it never copies an override into it.
            'cost_tier' => $isAdmin ? $this->getAttribute('cost_tier') : $this->costTier(),
            'prep_level' => $this->prep_level,
            $this->mergeWhen($isAdmin && $this->relationLoaded('allRegionalCosts'), fn () => [
                'regional_costs' => $this->allRegionalCosts->map(fn ($row) => [
                    'country' => $row->country,
                    'country_name' => config('countries.list')[$row->country] ?? $row->country,
                    'cost_tier' => $row->cost_tier,
                    'set_by_name' => $row->relationLoaded('setter') ? $row->setter?->name : null,
                    'updated_at' => $row->updated_at?->toIso8601String(),
                ])->values()->all(),
            ]),
            // The glycemic index, sodium and what a reviewer still has to fill in. Not for the patient
            // app: its payloads (a plan's foods, a meal log's food) stay exactly as they were.
            $this->mergeUnless(Auth::user()?->hasRole('client'), fn () => [
                'glycemic_index' => $this->glycemic_index === null ? null : (int) $this->glycemic_index,
                'sodium_mg_per_100g' => $this->sodium_mg_per_100g === null ? null : (float) $this->sodium_mg_per_100g,
                'nutrition_review_source' => $this->nutrition_review_source,
                'nutrition_reviewed_at' => $this->nutrition_reviewed_at?->toIso8601String(),
                'nutrition_reviewer_name' => $this->whenLoaded('nutritionReviewer', fn () => $this->nutritionReviewer?->name),
                'missing' => $this->missingFields(),
            ]),
        ];
    }
}
