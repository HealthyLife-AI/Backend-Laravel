<?php

namespace App\Http\Requests\Alerts;

use App\Models\Alert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Mark all as read" on the alerts page: the same optional filters as
 * IndexAlertRequest (minus is_read, which is implied), so the action
 * applies to exactly what the nutritionist is looking at.
 */
class ReadAllAlertsRequest extends FormRequest
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
            'subscriber_id' => ['sometimes', 'integer', Rule::exists('subscribers', 'id')],
            'severity' => ['sometimes', Rule::in(Alert::SEVERITIES)],
        ];
    }
}
