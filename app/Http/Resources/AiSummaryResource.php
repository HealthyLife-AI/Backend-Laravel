<?php

namespace App\Http\Resources;

use App\Models\AiSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiSummary
 */
class AiSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'week_start' => $this->week_start->toDateString(),
            'summary_text' => $this->summary_text,
            // BR-6's spirit for meal plans (never hide AI origin) applied
            // to prose: a real LLM write-up and the S5-05 templated
            // fallback must not read identically to the nutritionist.
            'is_fallback' => $this->is_fallback,
            'generated_at' => $this->generated_at?->toIso8601String(),
        ];
    }
}
