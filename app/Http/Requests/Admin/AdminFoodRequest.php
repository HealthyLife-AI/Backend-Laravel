<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Foods\SubmitFoodRequest;
use App\Models\Food;
use App\Support\FoodTagger;
use Illuminate\Validation\Rule;

/**
 * Admin create/edit of a catalog food: same fields and bounds as a
 * nutritionist submission, plus the food's allergen groups and
 * shopping-list section (only the admin sets these by hand). The route
 * permission (`foods.manage`) is the gate; the controller decides
 * source/status (admin-created foods go straight to approved).
 */
class AdminFoodRequest extends SubmitFoodRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'allergens' => ['sometimes', 'array'],
            'allergens.*' => ['distinct', Rule::in(FoodTagger::GROUPS)],
            'shopping_section' => ['sometimes', Rule::in(FoodTagger::SECTIONS)],
            // Phase 4, B3: null clears the tag (back to unknown).
            'cost_tier' => ['sometimes', 'nullable', Rule::in(Food::COST_TIERS)],
            'prep_level' => ['sometimes', 'nullable', Rule::in(Food::PREP_LEVELS)],
        ];
    }
}
