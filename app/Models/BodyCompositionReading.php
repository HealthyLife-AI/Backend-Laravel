<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscriber_id',
    'recorded_at',
    'source',
    'is_late',
    'weight_kg',
    'body_fat_percent',
    'muscle_mass_kg',
    'water_percent',
    'bmi',
    'waist_cm',
    'hip_cm',
    'thigh_cm',
    'arm_cm',
])]
class BodyCompositionReading extends Model
{
    /** BR-13: analyser-grade versus the client's own estimate. */
    public const SOURCE_CLINIC = 'clinic-analyser';

    public const SOURCE_SELF = 'self-reported';

    /**
     * BR-11: what a client can measure alone with a tape and a scale.
     * Body-fat, muscle mass, water percentage and BMI are absent on
     * purpose — the first three need a bio-impedance analyser, and BMI is
     * the nutritionist's own entry (she may keep the analyser's figure
     * instead of the computed one), not something a client submits.
     */
    public const CLIENT_MEASURABLE = ['weight_kg', 'waist_cm', 'hip_cm', 'thigh_cm', 'arm_cm'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'date',
            'is_late' => 'boolean',
            'edited_at' => 'datetime',
            'weight_kg' => 'decimal:2',
            'body_fat_percent' => 'decimal:1',
            'muscle_mass_kg' => 'decimal:2',
            'water_percent' => 'decimal:1',
            'bmi' => 'decimal:1',
            'waist_cm' => 'decimal:1',
            'hip_cm' => 'decimal:1',
            'thigh_cm' => 'decimal:1',
            'arm_cm' => 'decimal:1',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
