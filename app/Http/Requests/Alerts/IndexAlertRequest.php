<?php

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S5-02: the nutritionist's own alerts, optionally filtered by read state
 * and/or client. Both optional so the dashboard sidebar can ask for
 * "everything" or "just this client, unread" with the same endpoint.
 */
class IndexAlertRequest extends FormRequest
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
            'is_read' => ['sometimes', 'boolean'],
            'subscriber_id' => ['sometimes', 'integer', Rule::exists('subscribers', 'id')],
        ];
    }
}
