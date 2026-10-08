<?php

namespace App\Http\Requests\Auth;

use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/login. Patients: username + password (normalised like on
 * save, so any letter case and Arabic-Indic digits work). Nutritionists:
 * email + password. phone + password is still accepted until the patient
 * app ships username login.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'username' => ['required_without_all:email,phone', 'nullable', 'string', 'max:64'],
            'email' => ['required_without_all:username,phone', 'nullable', 'string', 'email'],
            'phone' => ['required_without_all:username,email', 'nullable', 'string', 'max:32'],
            'password' => ['required', 'string'],
        ];
    }
}
