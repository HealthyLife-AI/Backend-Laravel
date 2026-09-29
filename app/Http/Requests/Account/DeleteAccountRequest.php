<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * BR-18: deleting your own account takes your current password, so an access
 * token on its own (a lost phone, a leaked token) can't wipe a patient's
 * record. A wrong or missing password is a 422 on `password`, the same
 * shape as any other form error.
 */
class DeleteAccountRequest extends FormRequest
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
            'password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $hash = $this->user()?->password;

                    if ($hash === null || ! Hash::check((string) $value, $hash)) {
                        $fail('The password is incorrect.');
                    }
                },
            ],
        ];
    }
}
