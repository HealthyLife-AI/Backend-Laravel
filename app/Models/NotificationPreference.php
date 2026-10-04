<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's notification settings: push per category (system is always
 * on), quiet hours in their own timezone, and the language notifications
 * are written in. A null timezone means config('scheduling.timezone').
 */
#[Fillable(['user_id', 'locale', 'meals', 'measurements', 'nutritionist', 'plan', 'quiet_hours_enabled', 'quiet_start', 'quiet_end', 'timezone'])]
class NotificationPreference extends Model
{
    public const LOCALES = ['ar', 'en'];

    /** Categories the patient can switch off; `system` can't be. */
    public const TOGGLEABLE = ['meals', 'measurements', 'nutritionist', 'plan'];

    protected function casts(): array
    {
        return [
            'meals' => 'boolean',
            'measurements' => 'boolean',
            'nutritionist' => 'boolean',
            'plan' => 'boolean',
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone ?: (string) config('scheduling.timezone');
    }

    public static function for(User $user): self
    {
        $prefs = self::query()->firstOrCreate(['user_id' => $user->id]);

        // A new row only carries user_id in memory; load the column defaults.
        return $prefs->wasRecentlyCreated ? $prefs->refresh() : $prefs;
    }
}
