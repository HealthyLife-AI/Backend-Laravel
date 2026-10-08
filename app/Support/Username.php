<?php

namespace App\Support;

/**
 * Patient usernames: what the nutritionist types and what the patient
 * signs in with. Normalised the same way on save and on login, so
 * "Sara.K", " sara.k " and "sara.k" with Arabic-Indic digits are one name.
 */
final class Username
{
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]{2,29}$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtolower(trim(Digits::toLatin($value)));
    }

    public static function isValid(string $normalized): bool
    {
        return preg_match(self::PATTERN, $normalized) === 1;
    }

    /** Arabic letters get their own message: the usual typo is typing the name in Arabic. */
    public static function hasArabicLetters(string $value): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', Digits::toLatin($value)) === 1;
    }
}
