<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 4, A7: who streamed a report file, when and from where. Never the contents. */
#[Fillable(['report_id', 'user_id', 'role', 'ip', 'accessed_at'])]
class ReportAccessLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['accessed_at' => 'datetime'];
    }
}
