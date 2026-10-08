<?php

namespace App\Rules;

use App\Support\Username;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates an already-normalised patient username (see App\Support\Username). */
class PatientUsername implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The username must be text.');

            return;
        }

        if (Username::hasArabicLetters($value)) {
            $fail('The username must use English letters, digits, dot, underscore or hyphen — no Arabic letters.');

            return;
        }

        if (! Username::isValid($value)) {
            $fail('The username must be 3–30 characters: English letters, digits, dot, underscore or hyphen, starting with a letter or digit.');
        }
    }
}
