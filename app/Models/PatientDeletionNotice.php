<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-18: tells a nutritionist that a patient deleted their own account.
 * Deliberately only the patient's code and the date — see the migration.
 * Not scoped by NutritionistScope (that scope is for tables with a
 * `nutritionist_id` AND rows a client can also reach); the notices
 * endpoints filter on the caller explicitly.
 */
#[Fillable(['nutritionist_id', 'patient_code', 'deleted_at'])]
class PatientDeletionNotice extends Model
{
    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function nutritionist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nutritionist_id');
    }
}
