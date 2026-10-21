<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One patient's alert-threshold OVERRIDES. A NULL column means "use the
 * global default" - see App\Services\Alerts\AlertThresholds, the only place
 * these are read.
 */
#[Fillable(['subscriber_id', 'no_log_days', 'calorie_exceeded_streak_days', 'milestone_weight_change_kg', 'milestone_window_days', 'updated_by'])]
class SubscriberAlertSetting extends Model
{
    /** Request field => the config key whose default it overrides. */
    public const FIELDS = [
        'no_log_days' => 'adherence.late_after_days',
        'calorie_exceeded_streak_days' => 'alerts.calorie_exceeded_streak_days',
        'milestone_weight_change_kg' => 'alerts.milestone_weight_change_kg',
        'milestone_window_days' => 'alerts.milestone_window_days',
    ];

    protected function casts(): array
    {
        return [
            'no_log_days' => 'integer',
            'calorie_exceeded_streak_days' => 'integer',
            'milestone_weight_change_kg' => 'float',
            'milestone_window_days' => 'integer',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
