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
            // A roster-wide alerts list is unusable without knowing whose
            // alert it is — id alone forces a second lookup per row.
            // whenLoaded() so this never triggers a query on its own;
            // AlertController::index() eager-loads ['subscriber.user'].
            'subscriber_name' => $this->whenLoaded('subscriber', fn () => $this->subscriber->user->name),
            'subscriber_code' => $this->whenLoaded('subscriber', fn () => $this->subscriber->code),
            'type' => $this->type,
            'message' => $this->message,
            'is_read' => $this->is_read,
            // Whether the underlying condition is still true — distinct
            // from is_read, which only tracks whether the NUTRITIONIST has
            // seen it. `resolved_at` is only ever written for `no_log`/
            // `calories_exceeded` (see AlertEvaluationService) — a
            // `milestone` alert has no ongoing condition to resolve, so
            // this always reads `false` for one, never `true`. A UI
            // showing an open/resolved tag should key that off `type`,
            // not this field, or a milestone reads as a still-open
            // problem instead of the one-shot positive event it is.
            'is_resolved' => $this->resolved_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
