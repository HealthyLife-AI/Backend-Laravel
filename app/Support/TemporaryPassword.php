<?php

namespace App\Support;

/**
 * The password the system generates for a patient (on create and on a
 * reset). 10 characters from an alphabet without look-alikes (no 0 O o,
 * no 1 l I), with at least one upper-case letter, one lower-case letter
 * and one digit, so it already meets the password policy. CSPRNG only.
 *
 * The plain text exists only in the response of the call that generated
 * it: callers hash it at once and must never log it.
 */
final class TemporaryPassword
{
    public const LENGTH = 10;

    private const UPPER = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $all = self::UPPER.self::LOWER.self::DIGITS;
        $chars = [self::pick(self::UPPER), self::pick(self::LOWER), self::pick(self::DIGITS)];

        while (count($chars) < self::LENGTH) {
            $chars[] = self::pick($all);
        }

        // Fisher-Yates with random_int, so the guaranteed characters are not always first.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private static function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
