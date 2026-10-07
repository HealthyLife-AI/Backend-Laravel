<?php

namespace App\Http\Resources;

use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A patient's approved goal, medications and allergies, plus proposals
 * (the patient sees their own; the nutritionist the pending ones).
 *
 * @mixin Subscriber
 */
class HealthRecordsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $goal = $this->patientGoal;

        return [
            'goal' => $goal === null ? null : [
                'goal_type' => $goal->goal_type,
                'target_weight_kg' => $goal->target_weight_kg !== null ? (float) $goal->target_weight_kg : null,
                'target_date' => $goal->target_date?->toDateString(),
                'activity_level' => $this->healthProfile?->activity_level,
                'training_days_per_week' => $goal->training_days_per_week,
                'training_level' => $goal->training_level,
                'training_type' => $goal->training_type,
                'details' => $goal->details,
            ],
            'medications' => $this->medications->whereNull('archived_at')->map(fn (PatientMedication $m) => self::medication($m))->values(),
            'allergies' => $this->allergyItems->map(fn (PatientAllergy $a) => self::allergy($a))->values(),
            'proposals' => $this->proposals()->orderByDesc('id')
                ->when(! $request->user()?->hasRole('client'), fn ($q) => $q->where('status', 'pending'))
                ->when($request->user()?->hasRole('client'), fn ($q) => $q->where('created_at', '>=', now()->subDays(60)))
                ->get()->map(fn (ProfileProposal $p) => self::proposal($p))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function medication(PatientMedication $m): array
    {
        return [
            'id' => $m->id, 'name' => $m->name, 'dose' => $m->dose, 'frequency' => $m->frequency, 'timing' => $m->timing,
            'reason' => $m->reason, 'status' => $m->status, 'until_date' => $m->until_date?->toDateString(),
            'reviewed_at' => $m->reviewed_at?->toIso8601String(), 'review_note' => $m->review_note,
        ];
    }

    /** @return array<string, mixed> */
    public static function allergy(PatientAllergy $a): array
    {
        return ['id' => $a->id, 'group' => $a->group, 'other_text' => $a->other_text, 'label' => $a->label(), 'class' => $a->class, 'note' => $a->note];
    }

    /** @return array<string, mixed> */
    public static function proposal(ProfileProposal $p): array
    {
        return [
            'id' => $p->id, 'kind' => $p->kind, 'action' => $p->action, 'target_id' => $p->target_id, 'payload' => $p->payload,
            'status' => $p->status, 'decision_note' => $p->decision_note,
            'decided_at' => $p->decided_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(),
        ];
    }
}
