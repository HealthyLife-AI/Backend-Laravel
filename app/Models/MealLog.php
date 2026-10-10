<?php

namespace App\Models;

use App\Support\ClinicDay;
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
    'log_kind',
    'meal_type',
    'quantity_grams',
    'logged_at',
    'is_late',
])]
class MealLog extends Model
{
    /** BR-16: the four meals a log can belong to; the same set as `meals.name`. */
    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

    /** What the log was when made; planned and alternative count as on-plan (BR-9). Never re-derived later. */
    public const KIND_PLANNED = 'planned';

    public const KIND_ALTERNATIVE = 'alternative';

    public const KIND_OFF_PLAN = 'off_plan';

    public const ON_PLAN_KINDS = [self::KIND_PLANNED, self::KIND_ALTERNATIVE];

    /**
     * BR-9: the kind is fixed when the log is created, from the item it
     * points at then, so a later plan edit that deletes that item never
     * turns an on-plan log into off-plan.
     */
    protected static function booted(): void
    {
        static::creating(function (MealLog $log): void {
            if ($log->log_kind !== null) {
                return;
            }

            $item = $log->meal_item_id !== null ? MealItem::query()->find($log->meal_item_id) : null;

            $log->log_kind = match (true) {
                $item === null => self::KIND_OFF_PLAN,
                $item->parent_item_id === null => self::KIND_PLANNED,
                default => self::KIND_ALTERNATIVE,
            };
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_grams' => 'decimal:1',
            'logged_at' => 'datetime',
            'is_late' => 'boolean',
            'edited_at' => 'datetime',
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
        $query->whereIn($query->qualifyColumn('log_kind'), self::ON_PLAN_KINDS);
    }

    public function scopeLoggedBetween(Builder $query, string $from, string $to): void
    {
        // C: the clinic's days (SCHEDULE_TIMEZONE), converted to the UTC the timestamps are stored in.
        $query->whereBetween('logged_at', [ClinicDay::startUtc($from), ClinicDay::endUtc($to)]);
    }
}
