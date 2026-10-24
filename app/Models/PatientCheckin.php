<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 2, A: one periodic questionnaire for one due date. */
#[Fillable(['subscriber_id', 'due_on', 'status', 'answers', 'questionnaire_version', 'submitted_at', 'flagged', 'reviewed_at', 'reviewed_by'])]
class PatientCheckin extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'answers' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'flagged' => 'boolean',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
