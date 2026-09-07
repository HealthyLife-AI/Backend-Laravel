<?php

namespace App\Http\Requests\HealthProfiles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-09/FR-11: full health profile. This is an upsert (PUT) — the whole
 * profile is edited as one form (PRD F-3), so every field is required on
 * every save rather than supporting partial PATCH semantics.
 */
class UpdateHealthProfileRequest extends FormRequest
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
            'weight_kg' => ['required', 'numeric', 'between:1,500'],
            'height_cm' => ['required', 'numeric', 'between:30,272'],
            'age' => ['required', 'integer', 'between:1,120'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'activity_level' => ['required', Rule::in(['sedentary', 'light', 'moderate', 'active', 'very_active'])],

            'health_conditions' => ['nullable', 'array'],
            'health_conditions.*' => ['string', 'max:255'],

            'medications' => ['nullable', 'array'],
            'medications.*.name' => ['required_with:medications', 'string', 'max:255'],
            'medications.*.dose' => ['nullable', 'string', 'max:255'],
            'medications.*.schedule' => ['nullable', 'string', 'max:255'],

            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', 'max:255'],

            'food_preferences' => ['nullable', 'array'],
            'food_preferences.*' => ['string', 'max:255'],

            'surgery_history' => ['nullable', 'string'],
            'lab_notes' => ['nullable', 'string'],
            'nutritionist_notes' => ['nullable', 'string'],
        ];
    }
}
