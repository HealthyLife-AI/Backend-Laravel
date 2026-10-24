<?php

namespace App\Http\Requests\QuickRequests;

use App\Models\PatientQuickRequest;
use App\Support\ClinicDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Phase 1, E2: a patient's quick request. The reason is required; the note and occasion date are optional. */
class StoreQuickRequestRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(PatientQuickRequest::REASONS)],
            'note' => ['nullable', 'string', 'max:280'],
            'occasion_date' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail) {
                // The clinic's today, not the server's.
                if ($value !== null && $value < ClinicDay::today()->toDateString()) {
                    $fail('The occasion date cannot be in the past.');
                }
            }],
        ];
    }
}
