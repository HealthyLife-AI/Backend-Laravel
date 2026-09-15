<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S5-06 / FR-22: the client's mobile app registering its own push token.
 * Nullable-accepting on purpose — sending `fcm_token: null` is how the
 * app clears it (e.g. on logout or a denied notification permission),
 * distinct from simply never having called this endpoint.
 */
class StoreFcmTokenRequest extends FormRequest
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
            'fcm_token' => ['nullable', 'string', 'max:255'],
        ];
    }
}
