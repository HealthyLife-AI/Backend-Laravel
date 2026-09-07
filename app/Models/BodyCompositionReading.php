<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscriber_id',
    'recorded_at',
    'weight_kg',
    'body_fat_percent',
    'muscle_mass_kg',
    'water_percent',
    'waist_cm',
])]
class BodyCompositionReading extends Model
{
    protected function casts(): array
    {
        return [
            'recorded_at' => 'date',
            'weight_kg' => 'decimal:2',
            'body_fat_percent' => 'decimal:1',
            'muscle_mass_kg' => 'decimal:2',
            'water_percent' => 'decimal:1',
            'waist_cm' => 'decimal:1',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
