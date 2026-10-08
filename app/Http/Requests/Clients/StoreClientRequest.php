<?php

namespace App\Http\Requests\Clients;

use App\Rules\PatientUsername;
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
            'username' => ['required', 'string', new PatientUsername, Rule::unique('users', 'username')],
            'goal' => ['required', Rule::in(['weight_loss', 'weight_gain', 'weight_maintenance', 'health_monitoring'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // Says only that it is taken: nothing about whose it is.
        return ['username.unique' => 'This username is taken.'];
    }
}
