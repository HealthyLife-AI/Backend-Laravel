<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A nutritionist logs in by email; a client has no email (PRD F-1 — added
 * by name + phone only) and logs in by phone instead. Exactly one of the
 * two must be present.
 */
class LoginRequest extends FormRequest
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
            'email' => ['required_without:phone', 'nullable', 'string', 'email'],
            'phone' => ['required_without:email', 'nullable', 'string'],
            'password' => ['required', 'string'],
        ];
    }
}
