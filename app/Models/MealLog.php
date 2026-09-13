<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S4-01 / FR-17, BR-9: one thing the client actually ate.
 *
 * No `NutritionistScope` here, deliberately. That scope filters on a
 * `nutritionist_id` column this table doesn't carry; isolation instead
 * comes from the two ways a log is ever reached — the client's own
 * endpoint resolves the subscriber from the authenticated user, and the
 * nutritionist's endpoint route-binds a `Subscriber` and re-checks
 * `belongsToCaller()`. Same shape as `BodyCompositionReading`.
 */
#[Fillable([
    'subscriber_id',
    'idempotency_key',
    'food_id',
    'meal_item_id',
    'quantity_grams',
    'logged_at',
])]
class MealLog extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_grams' => 'decimal:1',
            'logged_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** Null when the client ate something outside their plan (BR-9). */
    public function mealItem(): BelongsTo
    {
        return $this->belongsTo(MealItem::class, 'meal_item_id');
    }

    /** BR-9: the on-plan half of the adherence ratio. */
    public function scopeOnPlan(Builder $query): void
    {
        $query->whereNotNull('meal_item_id');
    }

    public function scopeLoggedBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('logged_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }
}
