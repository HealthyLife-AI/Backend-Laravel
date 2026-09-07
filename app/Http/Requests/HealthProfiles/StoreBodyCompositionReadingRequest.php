<?php

namespace App\Http\Requests\HealthProfiles;

use Illuminate\Foundation\Http\FormRequest;

/** FR-10: one structured body-composition reading per visit. */
class StoreBodyCompositionReadingRequest extends FormRequest
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
            'recorded_at' => ['required', 'date', 'before_or_equal:today'],
            'weight_kg' => ['required', 'numeric', 'between:1,500'],
            'body_fat_percent' => ['nullable', 'numeric', 'between:0,100'],
            'muscle_mass_kg' => ['nullable', 'numeric', 'between:0,500'],
            'water_percent' => ['nullable', 'numeric', 'between:0,100'],
            'waist_cm' => ['nullable', 'numeric', 'between:0,300'],
        ];
    }
}
