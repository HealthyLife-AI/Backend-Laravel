<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Which country's regional data the current request reads: a nutritionist's own
 * country; a patient's nutritionist's country; the default ('PS', the country
 * foods.cost_tier belongs to) for an admin, a guest or a job.
 */
final class RegionalCountry
{
    public static function default(): string
    {
        return (string) config('countries.default', 'PS');
    }

    public static function current(): string
    {
        return self::of(Auth::user());
    }

    public static function of(?User $user): string
    {
        if ($user === null) {
            return self::default();
        }

        if ($user->hasRole('nutritionist')) {
            return (string) ($user->country ?: self::default());
        }

        if ($user->hasRole('client') && $user->nutritionist_id !== null) {
            // loadMissing: one query per request, remembered on the (per-request) user instance.
            return (string) ($user->loadMissing('nutritionist:id,country')->nutritionist?->country ?: self::default());
        }

        return self::default();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys((array) config('countries.list'));
    }
}
