<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One provider response's token usage. Never any text. See AiUsageRecorder and AiUsageReport. */
#[Fillable(['feature', 'nutritionist_id', 'subscriber_id', 'model', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens', 'total_tokens', 'attempt', 'success', 'latency_ms', 'created_at'])]
class AiUsageLog extends Model
{
    public const FEATURES = ['ai_draft', 'plan_summary', 'weekly_summary', 'weight_insight', 'ping', 'other'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['success' => 'boolean', 'created_at' => 'datetime'];
    }
}
