<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S5-04 / FR-21: one week's natural-language summary for a client.
 * `is_fallback` distinguishes a real LLM write-up from the S5-05
 * templated fallback — see the migration's docblock for why that is
 * never left implicit.
 */
#[Fillable(['subscriber_id', 'week_start', 'summary_text', 'is_fallback', 'generated_at'])]
class AiSummary extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'is_fallback' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
