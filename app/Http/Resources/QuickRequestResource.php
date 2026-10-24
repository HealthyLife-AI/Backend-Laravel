<?php

namespace App\Http\Resources;

use App\Models\PatientQuickRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 1, E: one quick request. The patient sees their own with the
 * response; the nutritionist's list adds whose it is (whenLoaded, so no
 * query of its own).
 *
 * @mixin PatientQuickRequest
 */
class QuickRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'note' => $this->note,
            'occasion_date' => $this->occasion_date?->toDateString(),
            'status' => $this->status,
            'response' => $this->response,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($this->relationLoaded('subscriber') && $this->subscriber !== null, fn () => [
                'subscriber_id' => $this->subscriber_id,
                'subscriber_name' => $this->subscriber->user?->name,
                'subscriber_code' => $this->subscriber->code,
            ]),
        ];
    }
}
