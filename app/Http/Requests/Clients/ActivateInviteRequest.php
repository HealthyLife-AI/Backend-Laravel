<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** FR-03: the client sets a password through their invite link. */
class ActivateInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint — the invite token itself is the credential
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }
}
