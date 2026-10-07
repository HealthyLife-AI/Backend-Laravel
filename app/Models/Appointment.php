<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An appointment between a patient and their nutritionist. */
#[Fillable(['subscriber_id', 'nutritionist_id', 'type', 'channel', 'starts_at', 'ends_at', 'topics', 'note'])]
class Appointment extends Model
{
    /** Minutes per type. */
    public const DURATIONS = ['follow_up' => 30, 'results_review' => 30, 'quick_consult' => 15];

    public const CHANNELS = ['whatsapp', 'phone', 'video'];

    public const TOPICS = ['weight', 'meals', 'plan_change', 'results', 'medications', 'other'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'topics' => 'array'];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
