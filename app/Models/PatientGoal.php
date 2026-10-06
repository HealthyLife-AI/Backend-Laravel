<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The patient's approved goal. One per patient. */
#[Fillable(['subscriber_id', 'goal_type', 'target_weight_kg', 'target_date', 'training_days_per_week', 'training_level', 'training_type', 'details'])]
class PatientGoal extends Model
{
    public const TYPES = ['weight_loss', 'weight_gain', 'muscle_gain', 'weight_maintenance', 'health_energy', 'medical_condition', 'other'];

    public const TRAINING_LEVELS = ['beginner', 'intermediate', 'advanced'];

    public const TRAINING_TYPES = ['strength', 'cardio', 'mixed', 'sports'];

    /** subscribers.goal is a narrower legacy enum (the milestone alert reads it). */
    public const LEGACY_GOAL = [
        'weight_loss' => 'weight_loss',
        'weight_gain' => 'weight_gain',
        'muscle_gain' => 'weight_gain',
        'weight_maintenance' => 'weight_maintenance',
        'health_energy' => 'health_monitoring',
        'medical_condition' => 'health_monitoring',
        'other' => 'health_monitoring',
    ];

    protected function casts(): array
    {
        return ['target_weight_kg' => 'decimal:2', 'target_date' => 'date', 'training_days_per_week' => 'integer'];
    }
}
