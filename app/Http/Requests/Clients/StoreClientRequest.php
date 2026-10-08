<?php

namespace App\Http\Requests\Clients;

use App\Rules\PatientUsername;
use App\Support\Phone;
use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-02: a nutritionist adds a client by name + phone + username (no email
 * — PRD F-1 / MVP spec §5.1). Phone is unique per nutritionist, not
 * globally (BR-1: siblings/family sharing a household line could
 * plausibly be clients of different nutritionists). The username is
 * unique across all users because login is global.
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `permission:clients.manage` route middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => Username::normalize($this->input('username'))]);
        }

        // Stored in international format so WhatsApp can open the chat directly.
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Phone::clean($this->input('phone'))]);
        }
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
                'regex:'.Phone::E164,
                Rule::unique('users', 'phone')->where('nutritionist_id', $this->user()->id),
            ],
            'username' => ['required', 'string', new PatientUsername, Rule::unique('users', 'username')],
            'goal' => ['required', Rule::in(['weight_loss', 'weight_gain', 'weight_maintenance', 'health_monitoring'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // Says only that it is taken: nothing about whose it is.
        return [
            'username.unique' => 'This username is taken.',
            'phone.regex' => 'Enter the phone in international format with the country code, e.g. +970599123456.',
        ];
    }
}
