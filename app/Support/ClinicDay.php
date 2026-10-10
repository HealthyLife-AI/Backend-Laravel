<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Days" as the clinic and its patients live them: in SCHEDULE_TIMEZONE
 * (scheduling.timezone), not UTC. A meal at 01:30 Gaza time belongs to that
 * local day, although its UTC date is still the day before. Timestamps stay
 * stored in UTC (app.timezone); only the day boundaries move.
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

    /** The clinic-local Y-m-d of a stored timestamp. */
    public static function ymdOf(CarbonInterface|string $at): string
    {
        return CarbonImmutable::parse($at, config('app.timezone'))->setTimezone(self::timezone())->toDateString();
    }

    /** Start of the clinic day $ymd, as a UTC (app timezone) string for queries on stored timestamps. */
    public static function startUtc(string $ymd): string
    {
        return self::date($ymd)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** Last second of the clinic day $ymd, as a UTC (app timezone) string. */
    public static function endUtc(string $ymd): string
    {
        return self::date($ymd)->addDay()->subSecond()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
