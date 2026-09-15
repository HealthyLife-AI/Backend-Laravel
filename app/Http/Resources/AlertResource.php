<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscriber_id' => $this->subscriber_id,
            'type' => $this->type,
            'message' => $this->message,
            'is_read' => $this->is_read,
            // Whether the underlying condition is still true — distinct
            // from is_read, which only tracks whether the NUTRITIONIST has
            // seen it. A milestone alert never carries this (see
            // AlertEvaluationService); it stays null for that type.
            'is_resolved' => $this->resolved_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
