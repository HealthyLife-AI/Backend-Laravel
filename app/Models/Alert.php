<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S5-01/S5-02 / FR-20: a rule-based proactive alert raised for a client.
 *
 * No `nutritionist_id` column, so no `NutritionistScope` here — isolation
 * is reached through the `subscriber` relation, whose own global scope
 * (`BelongsToNutritionist`) is honoured inside `whereHas('subscriber')`
 * subqueries. Marking one read still re-checks
 * `$alert->subscriber->belongsToCaller()` explicitly, the same
 * defense-in-depth the rest of this codebase applies to every
 * route-bound record.
 */
#[Fillable(['subscriber_id', 'type', 'message', 'is_read', 'resolved_at'])]
class Alert extends Model
{
    public const TYPE_NO_LOG = 'no_log';

    public const TYPE_CALORIES_EXCEEDED = 'calories_exceeded';

    public const TYPE_MILESTONE = 'milestone';

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }
}
