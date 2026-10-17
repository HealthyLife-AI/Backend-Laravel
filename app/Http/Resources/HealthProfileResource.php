<?php

namespace App\Http\Resources;

use App\Models\HealthProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

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
            // Item C: the soft floor the plan designer warns below. Served
            // from config/nutrition.php so that file (and its env
            // overrides) is the only place the numbers live — the
            // dashboard holds no copy. Both genders, so the warning
            // follows the gender field as it is edited.
            $this->mergeUnless(Auth::user()?->hasRole('client'), fn () => [
                'calorie_floor' => [
                    'female' => (int) config('nutrition.calorie_floor.female'),
                    'male' => (int) config('nutrition.calorie_floor.male'),
                ],
            ]),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
