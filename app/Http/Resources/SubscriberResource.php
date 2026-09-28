<?php

namespace App\Http\Resources;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscriber
 */
class SubscriberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
            'goal' => $this->goal,
            'status' => $this->status,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'adherence_status' => $this->adherence_status,
            'last_logged_at' => $this->last_logged_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
