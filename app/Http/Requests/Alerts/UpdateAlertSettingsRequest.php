<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pilot feedback 2, B3: any subset of the four thresholds. A field that is
 * ABSENT is left as it is; a field sent as null RESETS to the global
 * default. The bounds keep a typo out (a 30-day no-log alert is not an
 * alert), they are not clinical rules.
 */
class UpdateAlertSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'no_log_days' => ['sometimes', 'nullable', 'integer', 'between:1,14'],
            'calorie_exceeded_streak_days' => ['sometimes', 'nullable', 'integer', 'between:1,14'],
            'milestone_weight_change_kg' => ['sometimes', 'nullable', 'numeric', 'between:0.5,20', 'decimal:0,1'],
            'milestone_window_days' => ['sometimes', 'nullable', 'integer', 'between:7,90'],
        ];
    }
}
