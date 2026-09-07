<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscriber_id',
    'weight_kg',
    'height_cm',
    'age',
    'gender',
    'activity_level',
    'health_conditions',
    'medications',
    'allergies',
    'food_preferences',
    'surgery_history',
    'lab_notes',
    'nutritionist_notes',
    'daily_calorie_needs',
])]
class HealthProfile extends Model
{
    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:1',
            'health_conditions' => 'array',
            'medications' => 'array',
            'allergies' => 'array',
            'food_preferences' => 'array',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
