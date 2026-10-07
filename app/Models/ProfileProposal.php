<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the patient proposed to their goal, a medication or an
 * allergy. Nothing applies until the nutritionist approves it.
 */
#[Fillable(['subscriber_id', 'kind', 'action', 'target_id', 'payload'])]
class ProfileProposal extends Model
{
    public const KINDS = ['goal', 'medication', 'allergy'];

    public const ACTIONS = ['add', 'edit', 'remove'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
