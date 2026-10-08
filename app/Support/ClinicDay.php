<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * "Days" as the clinic and its patients live them: in SCHEDULE_TIMEZONE
 * (scheduling.timezone), not UTC. Between 00:00 and 03:00 Gaza time the UTC
 * date is still yesterday, which used to refuse a reading dated "today".
 */
final class ClinicDay
{
    public static function timezone(): string
    {
        return (string) config('scheduling.timezone');
    }

    /** Start of today in the clinic's timezone. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** A Y-m-d read as that clinic-local day (start of day, clinic timezone). */
    public static function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::parse($ymd, self::timezone())->startOfDay();
    }
}
