<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * PUT /me/password. A wrong current password is a 422 on
 * `current_password`; the new one follows the same rule as invite
 * activation and must differ from the current one.
 */
class ChangePasswordRequest extends FormRequest
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
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $hash = $this->user()?->password;

                    if ($hash === null || ! Hash::check((string) $value, $hash)) {
                        $fail('The current password is incorrect.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->mixedCase()->numbers()],
        ];
    }
}
