<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->getRoleNames()->first(),
            'nutritionist_id' => $this->nutritionist_id,
            // The mobile client app needs its own subscriber id to call
            // clients/{subscriber}/adherence and .../progress (both held
            // by `progress.view`, which client and nutritionist share —
            // see RolesAndPermissionsSeeder). Before this, the ONLY place
            // that id surfaced at all was embedded in GET /me/meal-plan's
            // response, which doesn't exist for a client with no plan yet
            // (204 No Content) — a client role with no plan had no way to
            // reach either endpoint. null for a nutritionist (no
            // Subscriber row of their own).
            'subscriber_id' => $this->subscriberProfile?->id,
        ];
    }
}
