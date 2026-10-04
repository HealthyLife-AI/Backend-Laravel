<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A task under a follow-up review; the patient marks it done. */
#[Fillable(['review_id', 'subscriber_id', 'title', 'sort_order'])]
class FollowUpTask extends Model
{
    public const MAX_TITLE_LENGTH = 200;

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(FollowUpReview::class, 'review_id');
    }
}
