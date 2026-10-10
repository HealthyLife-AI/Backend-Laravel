<?php

namespace App\Http\Resources;

use App\Models\Subscriber;
use App\Services\Consent\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscriber
 */
class SubscriberResource extends JsonResource
{
    private bool $withConsent = false;

    /**
     * BR-17: add the patient's consent status (last accepted version and
     * date, and whether it is the current one). Only the single-patient
     * view asks for it — the roster doesn't need a query per row.
     */
    public function withConsent(): static
    {
        $this->withConsent = true;

        return $this;
    }

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
            // Patients sign in with it; null for patients added before usernames.
            'username' => $this->user->username,
            // Legacy 4-value goal (the milestone alert reads it); show goal_type when set.
            'goal' => $this->goal,
            // B10: the structured goal (7 types) the nutritionist and patient edit.
            'goal_type' => $this->patientGoal?->goal_type,
            'status' => $this->status,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'adherence_status' => $this->adherence_status,
            'last_logged_at' => $this->last_logged_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            // Patient-proposed profile changes awaiting the nutritionist (only on the roster).
            $this->mergeWhen(isset($this->pending_proposals_count), fn () => ['pending_proposals_count' => (int) $this->pending_proposals_count]),
            $this->mergeWhen($this->withConsent, fn () => [
                'consent' => app(ConsentService::class)->dashboardStatus($this->user),
            ]),
        ];
    }
}
