<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 4, A: metadata of one uploaded medical report. The file is on the private
 * reports disk, encrypted; `path` is itself stored encrypted and is never serialized.
 */
#[Fillable(['subscriber_id', 'uploaded_by', 'category', 'title', 'report_date', 'mime', 'size_bytes', 'pages', 'path', 'sha256', 'nutritionist_note', 'reviewed_at', 'reviewed_by', 'purged_at'])]
class PatientReport extends Model
{
    use SoftDeletes;

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'size_bytes' => 'integer',
            'pages' => 'integer',
            'reviewed_at' => 'datetime',
            'purged_at' => 'datetime',
            'path' => 'encrypted',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
