<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 1, B: how often this patient is due to record body measurements, and when next. */
#[Fillable(['subscriber_id', 'interval_days', 'next_due_on', 'last_reminded_on', 'updated_by'])]
class SubscriberMeasurementSchedule extends Model
{
    /** The presets; any whole number of days from MIN to MAX is accepted as a custom interval. */
    public const PRESETS = [7, 14, 30];

    public const MIN_DAYS = 3;

    public const MAX_DAYS = 90;

    protected function casts(): array
    {
        return [
            'interval_days' => 'integer',
            'next_due_on' => 'date',
            'last_reminded_on' => 'date',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
