<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Appointment */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tz = (string) config('scheduling.timezone');
        $isPatient = (bool) $request->user()?->hasRole('client');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'channel' => $this->channel,
            'starts_at' => $this->starts_at->setTimezone($tz)->toIso8601String(),
            'ends_at' => $this->ends_at->setTimezone($tz)->toIso8601String(),
            'status' => $this->status,
            'topics' => $this->topics ?? [],
            'note' => $this->note,
            'cancelled_by' => $this->cancelled_by,
            'cancel_reason' => $this->cancel_reason,
            // The video link is the nutritionist's default meeting link.
            'meeting_link' => $this->channel === 'video' ? User::query()->find($this->nutritionist_id)?->nutritionistProfile?->meeting_link : null,
            $this->mergeUnless($isPatient, fn () => [
                'patient' => ['id' => $this->subscriber_id, 'name' => $this->subscriber?->user?->name, 'code' => $this->subscriber?->code],
            ]),
        ];
    }
}
