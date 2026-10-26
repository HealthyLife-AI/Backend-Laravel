<?php

namespace App\Http\Requests\Wellbeing;

use App\Models\PatientWellbeingLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 4, C2: one day's entry. At least one field must be present; a field
 * left out (or null) is cleared, since the entry is replaced as a whole. The date
 * comes from the URL and is checked by the service (not future, not older than 7 days).
 */
class SaveWellbeingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $oneOf = fn (string $self) => 'required_without_all:'.implode(',', array_diff(['sleep_hours', 'sleep_quality', 'stress', 'mood', 'note'], [$self]));

        return [
            'sleep_hours' => ['nullable', 'numeric', 'between:0,16', 'decimal:0,1', $oneOf('sleep_hours')],
            'sleep_quality' => ['nullable', Rule::in(PatientWellbeingLog::SLEEP_QUALITIES), $oneOf('sleep_quality')],
            'stress' => ['nullable', Rule::in(PatientWellbeingLog::STRESS_LEVELS), $oneOf('stress')],
            'mood' => ['nullable', Rule::in(PatientWellbeingLog::MOODS), $oneOf('mood')],
            'note' => ['nullable', 'string', 'max:280', $oneOf('note')],
        ];
    }
}
