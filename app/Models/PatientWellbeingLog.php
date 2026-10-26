<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 4, C: one day's sleep, stress and mood, as the patient reports them. */
#[Fillable(['subscriber_id', 'log_date', 'sleep_hours', 'sleep_quality', 'stress', 'mood', 'note'])]
class PatientWellbeingLog extends Model
{
    public const SLEEP_QUALITIES = ['good', 'ok', 'poor'];

    public const STRESS_LEVELS = ['low', 'medium', 'high'];

    public const MOODS = ['good', 'ok', 'low'];

    /** What the patient may back-fill: today and this many days before it. */
    public const MAX_AGE_DAYS = 7;

    /** The widest range a list may ask for. */
    public const MAX_RANGE_DAYS = 60;

    /** The nutritionist's summary covers this many days up to today. */
    public const SUMMARY_DAYS = 14;

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'sleep_hours' => 'decimal:1',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
