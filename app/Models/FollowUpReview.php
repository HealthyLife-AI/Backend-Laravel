<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the nutritionist writes for a patient between sessions: a rating
 * (one of three states, never a number), a note, key points, and tasks.
 * The patient acknowledges it («فهمت»); editing it afterwards clears that.
 */
#[Fillable(['subscriber_id', 'nutritionist_id', 'rating', 'note', 'key_points'])]
class FollowUpReview extends Model
{
    public const RATINGS = ['on_track', 'small_adjustment', 'review_together'];

    public const MAX_NOTE_LENGTH = 2000;

    public const MAX_KEY_POINTS = 10;

    public const MAX_TASKS = 10;

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'acknowledged_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(FollowUpTask::class, 'review_id')->orderBy('sort_order')->orderBy('id');
    }
}
