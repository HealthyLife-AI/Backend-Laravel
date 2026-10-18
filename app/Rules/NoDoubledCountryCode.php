<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a phone whose country code is written twice in a row
 * ("+970970599123456"), the mark of a number pasted in full into a field
 * that already carried the country code.
 *
 * Runs on the value Phone::clean() has already normalised, so "00970970…"
 * and "+970 970 …" are caught the same way.
 */
class NoDoubledCountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $code = Phone::doubledCountryCode($value);

        if ($code !== null) {
            $fail("This number repeats the country code (+{$code}{$code}…). Remove the extra +{$code} and try again.");
        }
    }
}
