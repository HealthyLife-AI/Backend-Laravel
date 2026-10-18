<?php

namespace App\Http\Requests\HealthProfiles;

use App\Support\ClinicDay;
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
            'recorded_at' => ['required', 'date', 'before_or_equal:'.ClinicDay::today()->toDateString()],
            'weight_kg' => ['required', 'numeric', 'between:1,500'],
            'body_fat_percent' => ['nullable', 'numeric', 'between:0,100'],
            'muscle_mass_kg' => ['nullable', 'numeric', 'between:0,500'],
            'water_percent' => ['nullable', 'numeric', 'between:0,100'],
            // The nutritionist's own figure (the analyser's, or the
            // computed one she accepted). The bounds only catch a typo:
            // below 10 or above 80 is not a person, it is a slipped
            // decimal point. BR-11 keeps this off the client's side.
            'bmi' => ['nullable', 'numeric', 'between:10,80'],
            'waist_cm' => ['nullable', 'numeric', 'between:0,300'],
            // S4-16: the nutritionist measures everything the client can,
            // plus the analyser-only figures above.
            'hip_cm' => ['nullable', 'numeric', 'between:0,300'],
            'thigh_cm' => ['nullable', 'numeric', 'between:0,200'],
            'arm_cm' => ['nullable', 'numeric', 'between:0,150'],
        ];
    }
}
