<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1, E: a structured request from the patient to the nutritionist
 * (not a chat): one of a fixed set of reasons, an optional short note, and
 * one plain-text answer. No automatic plan change ever follows from it.
 */
#[Fillable(['subscriber_id', 'reason', 'note', 'occasion_date', 'status', 'response', 'responded_by', 'responded_at'])]
class PatientQuickRequest extends Model
{
    public const REASONS = ['special_occasion', 'food_unavailable', 'craving', 'cannot_continue', 'other'];

    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ANSWERED, self::STATUS_CLOSED];

    /** Requests a patient may send per day (clinic day). */
    public const DAILY_LIMIT = 5;

    protected function casts(): array
    {
        return [
            'occasion_date' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
