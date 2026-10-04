<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of a patient's notification inbox. Created only through
 * NotificationService, which also decides whether it is pushed.
 */
#[Fillable(['user_id', 'category', 'type', 'title', 'body', 'data', 'dedupe_key'])]
class PatientNotification extends Model
{
    public const CATEGORIES = ['meals', 'measurements', 'nutritionist', 'plan', 'system'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }
}
