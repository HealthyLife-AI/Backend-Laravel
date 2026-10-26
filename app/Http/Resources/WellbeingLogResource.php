<?php

namespace App\Http\Resources;

use App\Models\PatientWellbeingLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 4, C: one wellbeing entry.
 *
 * @mixin PatientWellbeingLog
 */
class WellbeingLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->log_date->toDateString(),
            'sleep_hours' => $this->sleep_hours === null ? null : (float) $this->sleep_hours,
            'sleep_quality' => $this->sleep_quality,
            'stress' => $this->stress,
            'mood' => $this->mood,
            'note' => $this->note,
        ];
    }
}
