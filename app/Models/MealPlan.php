<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * S3-01 / FR-12: a nutrition plan assigned to a client, or a reusable
 * template. See the migration for why `subscriber_id` is nullable and
 * what each `status` value means; see `belongsToCaller()` for why this
 * model's isolation check is NOT the same `belongsToCaller()` shape as
 * `Subscriber`'s.
 */
#[Fillable(['subscriber_id', 'created_by', 'is_template', 'is_ai_draft', 'start_date', 'status'])]
class MealPlan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'is_ai_draft' => 'boolean',
            'start_date' => 'date',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class)->orderBy('day_index')->orderBy('sort_order');
    }

    /**
     * A route-model-bound `MealPlan` needs the same explicit ownership
     * check every route-bound `Subscriber` needs (see `Subscriber`'s
     * docblock) — but a template has no subscriber to delegate to, so
     * this checks `created_by` for templates and defers to the
     * subscriber's own check otherwise, rather than trying to force one
     * shared shape onto both cases.
     */
    public function belongsToCaller(): bool
    {
        if ($this->is_template) {
            return $this->created_by === Auth::id();
        }

        return $this->subscriber !== null && $this->subscriber->belongsToCaller();
    }
}
