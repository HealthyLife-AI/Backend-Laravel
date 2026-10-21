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
        ];
    }
}
