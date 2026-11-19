<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Foods\SubmitFoodRequest;
use App\Models\Food;
use App\Support\FoodTagger;
use Illuminate\Validation\Rule;

/**
 * Admin create/edit of a catalog food: same fields and bounds as a
 * nutritionist submission, plus the food's allergen groups, shopping-list
 * section, and which plan slots it's allowed in (only the admin sets
 * these by hand). The route permission (`foods.manage`) is the gate; the
 * controller decides source/status (admin-created foods go straight to
 * approved).
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
            // null = any slot; [] = excluded from every AI-drafted plan
            // (see the meal_slots migration); a non-empty list restricts
            // AiDraftPlanService to those slots. 'array' alone accepts [].
            'meal_slots' => ['sometimes', 'nullable', 'array'],
            'meal_slots.*' => ['distinct', Rule::in(Food::MEAL_SLOTS)],
        ];
    }
}
