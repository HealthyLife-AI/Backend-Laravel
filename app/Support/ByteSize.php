<?php

namespace App\Support;

/**
 * Human-readable byte sizes ("49 MB") without ext-intl.
 *
 * `Illuminate\Support\Number::fileSize()` formats through `NumberFormatter`, so it
 * throws when the intl extension is missing. composer.json does not require intl
 * and production builds with Nixpacks, so it is not guaranteed there: db:backup
 * crashed after the dump and before the upload (audit F-OPS-01). Same steps and
 * units as Number::fileSize() at precision 0.
 */
final class ByteSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int|float $bytes): string
    {
        $unit = 0;

        while (abs($bytes) / 1024 > 0.9 && $unit < count(self::UNITS) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return number_format(round($bytes)).' '.self::UNITS[$unit];
    }
}
