<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a patient may see of their own nutritionist: how to address them and
 * how to reach them, their bio and usual reply hours, and nothing else — no e-mail, no phone, no id, no plan
 * tier. Built from the nutritionist's `User` row; the profile may not exist
 * yet (created on the nutritionist's first visit to their profile screen),
 * in which case its fields are null.
 *
 * @mixin User
 */
class PatientNutritionistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->nutritionistProfile;

        return [
            'name' => $this->name,
            'gender' => $profile?->gender,
            'clinic_name' => $profile?->clinic_name,
            'specialty' => $profile?->specialty,
            'whatsapp_number' => $profile?->whatsapp_number,
            'bio' => $profile?->bio,
            'reply_hours' => $profile?->reply_hours,
        ];
    }
}
