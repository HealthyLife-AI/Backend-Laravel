<?php

namespace App\Http\Resources;

use App\Models\BodyCompositionReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BodyCompositionReading
 */
class BodyCompositionReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recorded_at' => $this->recorded_at->toDateString(),
            // BR-13: the nutritionist must always be able to tell an
            // analyser reading from the client's own estimate, so the
            // flag travels with every reading rather than being inferred.
            'source' => $this->source,
            'weight_kg' => (float) $this->weight_kg,
            'body_fat_percent' => $this->body_fat_percent !== null ? (float) $this->body_fat_percent : null,
            'muscle_mass_kg' => $this->muscle_mass_kg !== null ? (float) $this->muscle_mass_kg : null,
            'water_percent' => $this->water_percent !== null ? (float) $this->water_percent : null,
            'waist_cm' => $this->waist_cm !== null ? (float) $this->waist_cm : null,
            'hip_cm' => $this->hip_cm !== null ? (float) $this->hip_cm : null,
            'thigh_cm' => $this->thigh_cm !== null ? (float) $this->thigh_cm : null,
            'arm_cm' => $this->arm_cm !== null ? (float) $this->arm_cm : null,
        ];
    }
}
