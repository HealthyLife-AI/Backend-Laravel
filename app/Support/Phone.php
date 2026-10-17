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

    /**
     * Calling codes of the countries the dashboard offers when adding a
     * patient (plus Libya, where one of the pilot nutritionists works).
     * Doubling is only checked for these: a bare "any 1-3 digits repeated"
     * rule would reject real numbers in countries whose national numbers
     * can begin with their own code.
     */
    public const KNOWN_COUNTRY_CODES = ['970', '972', '962', '966', '971', '965', '974', '973', '968', '967', '961', '963', '964', '218', '20', '90', '44', '1'];

    /**
     * The country code a cleaned international number repeats straight
     * after itself, or null. "+970970599123456" -> "970".
     *
     * That is what pasting "+970599123456" into a field that already has
     * +970 selected produces (the dashboard stopped doing this; numbers
     * stored before then, and any other client, can still carry it).
     * It is never a number anyone owns: the national part would start
     * with its own country code.
     */
    public static function doubledCountryCode(string $cleaned): ?string
    {
        if (! str_starts_with($cleaned, '+')) {
            return null;
        }

        $digits = substr($cleaned, 1);

        foreach (self::KNOWN_COUNTRY_CODES as $code) {
            // Something must follow the second copy, or "+9709" would qualify.
            if (str_starts_with($digits, $code.$code) && strlen($digits) > strlen($code) * 2) {
                return $code;
            }
        }

        return null;
    }

    public static function isInternational(string $cleaned): bool
    {
        return preg_match(self::E164, $cleaned) === 1;
    }
}
