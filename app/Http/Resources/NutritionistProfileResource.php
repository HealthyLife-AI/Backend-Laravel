<?php

namespace App\Http\Resources;

use App\Models\NutritionistProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NutritionistProfile
 */
class NutritionistProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'specialty' => $this->specialty,
            'clinic_name' => $this->clinic_name,
            'bio' => $this->bio,
            // Readable but not writable (see UpdateNutritionistProfileRequest):
            // the dashboard shows the current tier, billing sets it.
            'plan_tier' => $this->plan_tier,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
