<?php

namespace App\Http\Requests\MealPlans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Item C: the approved target and the plan summary on their own, so the
 * nutritionist can revise her decision on the ACTIVE plan without
 * resubmitting every meal (which `UpdateMealPlanRequest` requires) and
 * without re-activating.
 *
 * Same bounds as `StoreMealPlanRequest`: a typo guard, not a clinical
 * rule. Both fields are nullable on purpose — sending null clears the
 * target and falls the client back to the system suggestion.
 */
class UpdatePlanDecisionRequest extends FormRequest
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
        return [
            'approved_calories' => ['sometimes', 'nullable', 'integer', 'between:800,6000'],
            'plan_summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'patient_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],

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
