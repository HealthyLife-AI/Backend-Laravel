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
#[Fillable(['subscriber_id', 'quick_request_id', 'checkin_id', 'patient_report_id', 'type', 'severity', 'message', 'is_read', 'resolved_at'])]
class Alert extends Model
{
    public const TYPE_NO_LOG = 'no_log';

    public const TYPE_CALORIES_EXCEEDED = 'calories_exceeded';

    public const TYPE_MILESTONE = 'milestone';

    /** Phase 1: the measurement is 3+ days past its due date. */
    public const TYPE_MEASUREMENT_OVERDUE = 'measurement_overdue';

    /** Phase 1: the patient sent a quick request. */
    public const TYPE_QUICK_REQUEST = 'quick_request';

    /** Phase 2: a submitted check-in whose answers need the nutritionist's attention. */
    public const TYPE_CHECKIN_FLAG = 'checkin_flag';

    /** Phase 4, A6: a patient uploaded a medical report. */
    public const TYPE_REPORT_UPLOADED = 'report_uploaded';

    public const TYPES = [
        self::TYPE_NO_LOG, self::TYPE_CALORIES_EXCEEDED, self::TYPE_MILESTONE,
        self::TYPE_MEASUREMENT_OVERDUE, self::TYPE_QUICK_REQUEST, self::TYPE_CHECKIN_FLAG, self::TYPE_REPORT_UPLOADED,
    ];

    public const SEVERITY_NORMAL = 'normal';

    public const SEVERITY_IMPORTANT = 'important';

    public const SEVERITIES = [self::SEVERITY_NORMAL, self::SEVERITY_IMPORTANT];

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
