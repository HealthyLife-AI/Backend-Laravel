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
        ];
    }
}
