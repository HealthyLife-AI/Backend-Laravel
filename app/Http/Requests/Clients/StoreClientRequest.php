<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-02: a nutritionist adds a client by name + phone only (no email —
 * PRD F-1 / MVP spec §5.1). Phone is unique per nutritionist, not
 * globally (BR-1: siblings/family sharing a household line could
 * plausibly be clients of different nutritionists).
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `permission:clients.manage` route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('users', 'phone')->where('nutritionist_id', $this->user()->id),
            ],
            'goal' => ['required', Rule::in(['weight_loss', 'weight_gain', 'weight_maintenance', 'health_monitoring'])],
        ];
    }
}
