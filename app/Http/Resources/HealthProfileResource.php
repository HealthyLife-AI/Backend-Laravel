<?php

namespace App\Http\Resources;

use App\Models\HealthProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HealthProfile
 */
class HealthProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'weight_kg' => (float) $this->weight_kg,
            'height_cm' => (float) $this->height_cm,
            'age' => $this->age,
            'gender' => $this->gender,
            'activity_level' => $this->activity_level,
            'health_conditions' => $this->health_conditions ?? [],
            'medications' => $this->medications ?? [],
            'allergies' => $this->allergies ?? [],
            'food_preferences' => $this->food_preferences ?? [],
            'surgery_history' => $this->surgery_history,
            'lab_notes' => $this->lab_notes,
            'nutritionist_notes' => $this->nutritionist_notes,
            'daily_calorie_needs' => $this->daily_calorie_needs,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
