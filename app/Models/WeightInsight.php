<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 3, C: the cached explanation of the weight change between two readings. */
#[Fillable(['subscriber_id', 'from_date', 'to_date', 'facts', 'text_ar', 'is_fallback', 'fallback_reason', 'generated_at'])]
class WeightInsight extends Model
{
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'facts' => 'array',
            'is_fallback' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }
}
