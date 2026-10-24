<?php

namespace App\Http\Requests\MealPlans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S3-01/FR-12, BR-4: a plan's meals and items in one payload — matches
 * how a plan designer actually authors one (a whole day/week at a time,
 * not item-by-item requests). Alternatives nest under the planned item
 * they belong to (`items.*.alternatives`) rather than being flat rows
 * with a client-supplied `parent_item_id`: a create request has no real
 * item IDs yet to point at, so the natural authoring shape — "this item,
 * with these alternatives" — is also the only shape that doesn't need
 * one. `MealPlanService` flattens this into `parent_item_id`-linked rows
 * when it persists.
 */
class StoreMealPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $approvedFood = Rule::exists('foods', 'id')->where('status', 'approved');

        return [
            'start_date' => ['nullable', 'date'],

            // Item C, nutritionist-only. The bounds are a typo guard, not
            // a clinical rule — a floor warning lives in the designer
            // (config/nutrition.php) and never blocks the save.
            'approved_calories' => ['nullable', 'integer', 'between:800,6000'],
            'plan_summary' => ['nullable', 'string', 'max:1000'],
            'patient_instructions' => ['nullable', 'string', 'max:2000'],

            'meals' => ['required', 'array', 'min:1'],
            'meals.*.name' => ['required', Rule::in(['breakfast', 'snack', 'lunch', 'dinner'])],
            'meals.*.day_index' => ['nullable', 'integer', 'between:0,6'],
            'meals.*.sort_order' => ['sometimes', 'integer', 'min:0'],

            'meals.*.items' => ['required', 'array', 'min:1'],
            'meals.*.items.*.food_id' => ['required', 'integer', $approvedFood],
            'meals.*.items.*.quantity_grams' => ['required', 'numeric', 'min:1', 'max:5000'],

            'meals.*.items.*.alternatives' => ['sometimes', 'array'],
            'meals.*.items.*.alternatives.*.food_id' => ['required', 'integer', $approvedFood],
            'meals.*.items.*.alternatives.*.quantity_grams' => ['required', 'numeric', 'min:1', 'max:5000'],

            // Phase 1, A: the physical activity prescribed with the plan. An
            // absent key leaves the stored activities alone; a present one
            // (even an empty list) replaces them all, like `meals`.
            'activities' => ['sometimes', 'array', 'max:20'],
            'activities.*.title' => ['required', 'string', 'max:120'],
            'activities.*.description' => ['nullable', 'string', 'max:1000'],
            'activities.*.duration_minutes' => ['required', 'integer', 'between:1,300'],
            'activities.*.times_per_day' => ['sometimes', 'integer', 'between:1,6'],
            'activities.*.days_per_week' => ['nullable', 'integer', 'between:1,7'],
        ];
    }
}
