<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One of the patient's medications or supplements (information only: the system never advises on them). */
#[Fillable(['subscriber_id', 'name', 'dose', 'frequency', 'timing', 'reason', 'status', 'until_date'])]
class PatientMedication extends Model
{
    public const TIMINGS = ['before', 'with', 'after', 'empty_stomach', 'any'];

    public const STATUSES = ['ongoing', 'until'];

    protected function casts(): array
    {
        return ['until_date' => 'date', 'archived_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
