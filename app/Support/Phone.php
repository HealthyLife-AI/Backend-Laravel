<?php

namespace App\Support;

/**
 * Patient phone numbers are stored in international format (+970599123456)
 * so the dashboard can open a WhatsApp chat with the patient directly.
 * Rows saved before this rule stay as they were.
 */
final class Phone
{
    public const E164 = '/^\+[1-9][0-9]{7,14}$/';

    /** Spaces, dashes, dots and brackets removed, Eastern digits to 0-9, a leading 00 to +. */
    public static function clean(string $value): string
    {
        $value = preg_replace('/[\s\-.()\x{200E}\x{200F}]/u', '', Digits::toLatin(trim($value)));

        return str_starts_with($value, '00') ? '+'.substr($value, 2) : $value;
    }

    public static function isInternational(string $cleaned): bool
    {
        return preg_match(self::E164, $cleaned) === 1;
    }
}
