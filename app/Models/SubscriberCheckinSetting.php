<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 2, A: how often this patient gets the short periodic questionnaire. Null interval = off. */
#[Fillable(['subscriber_id', 'interval_days', 'next_due_on', 'updated_by'])]
class SubscriberCheckinSetting extends Model
{
    public const INTERVALS = [7, 14];

    protected function casts(): array
    {
        return ['interval_days' => 'integer', 'next_due_on' => 'date'];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
