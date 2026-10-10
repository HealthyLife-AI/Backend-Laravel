<?php

namespace App\Services\HealthRecords;

use App\Exceptions\ApiCodeException;
use App\Models\HealthProfile;
use App\Models\PatientAllergy;
use App\Models\PatientGoal;
use App\Models\PatientMedication;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Nutrition\NutritionCalculatorService;
use App\Support\AllergyGroups;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The patient's goal, medications and allergies.
 *
 * The nutritionist edits them directly. The patient only proposes (add,
 * edit or remove): a proposal applies when the nutritionist approves it,
 * so removing a confirmed allergy can never happen silently. After every
 * change the old JSON columns on health_profiles are rewritten from these
 * records, so older readers keep working.
 */
class HealthRecordService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NutritionCalculatorService $nutrition,
    ) {}

    // ---- validation -------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validated(string $kind, array $data): array
    {
        $rules = match ($kind) {
            'goal' => [
                'goal_type' => ['required', Rule::in(PatientGoal::TYPES)],
                'target_weight_kg' => ['nullable', 'numeric', 'between:20,400'],
                'target_date' => ['nullable', 'date', 'after:today'],
                'activity_level' => ['nullable', Rule::in(['sedentary', 'light', 'moderate', 'active', 'very_active'])],
                'training_days_per_week' => ['nullable', 'integer', 'between:0,7'],
                'training_level' => ['nullable', Rule::in(PatientGoal::TRAINING_LEVELS)],
                'training_type' => ['nullable', Rule::in(PatientGoal::TRAINING_TYPES)],
                'details' => ['nullable', 'string', 'max:300'],
            ],
            'medication' => [
                'name' => ['required', 'string', 'max:100'],
                'dose' => ['nullable', 'string', 'max:100'],
                'frequency' => ['nullable', 'string', 'max:100'],
                'timing' => ['nullable', Rule::in(PatientMedication::TIMINGS)],
                'reason' => ['nullable', 'string', 'max:200'],
                'status' => ['nullable', Rule::in(PatientMedication::STATUSES)],
                'until_date' => ['nullable', 'required_if:status,until', 'date', 'after_or_equal:today'],
            ],
            'allergy' => [
                'group' => ['required', Rule::in(PatientAllergy::GROUPS)],
                'other_text' => ['nullable', 'required_if:group,other', 'string', 'max:100'],
                'class' => ['required', Rule::in(PatientAllergy::CLASSES)],
                'note' => ['nullable', 'string', 'max:300'],
            ],
        };

        $valid = Validator::make($data, $rules)->validate();

        // Training fields only mean something for muscle gain.
        if ($kind === 'goal' && $valid['goal_type'] !== 'muscle_gain') {
            $valid['training_level'] = null;
            $valid['training_type'] = null;
        }

        return $valid;
    }

    // ---- direct edits (nutritionist) --------------------------------------

    /** @param  array<string, mixed>  $data  validated goal */
    public function setGoal(Subscriber $subscriber, array $data): PatientGoal
    {
        return DB::transaction(function () use ($subscriber, $data) {
            $goal = PatientGoal::query()->updateOrCreate(
                ['subscriber_id' => $subscriber->id],
                collect($data)->except('activity_level')->all(),
            );

            $subscriber->forceFill(['goal' => PatientGoal::LEGACY_GOAL[$goal->goal_type]])->save();

            if (filled($data['activity_level'] ?? null) && ($profile = $subscriber->healthProfile) !== null) {
                $profile->activity_level = $data['activity_level'];
                $profile->daily_calorie_needs = $this->nutrition->calculateForProfile($profile);
                $profile->save();
            }

            return $goal;
        });
    }

    /** @param  array<string, mixed>  $data  validated medication */
    public function addMedication(Subscriber $subscriber, array $data): PatientMedication
    {
        $med = PatientMedication::create($this->medicationAttributes($data) + ['subscriber_id' => $subscriber->id]);
        $this->mirror($subscriber);

        return $med;
    }

    /** @param  array<string, mixed>  $data */
    public function updateMedication(PatientMedication $med, array $data): PatientMedication
    {
        $med->fill($this->medicationAttributes($data))->save();
        $this->mirror($med->subscriber_id);

        return $med;
    }

    public function archiveMedication(PatientMedication $med): void
    {
        $med->forceFill(['archived_at' => now()])->save();
        $this->mirror($med->subscriber_id);
    }

    public function reviewMedication(PatientMedication $med, ?string $note): PatientMedication
    {
        $med->forceFill(['reviewed_at' => now(), 'review_note' => $note])->save();

        return $med;
    }

    /** @param  array<string, mixed>  $data  validated allergy */
    public function addAllergy(Subscriber $subscriber, array $data): PatientAllergy
    {
        $this->assertAllergyNotRecorded($subscriber, $data);
        $allergy = PatientAllergy::create($this->allergyAttributes($data) + ['subscriber_id' => $subscriber->id]);
        $this->mirror($subscriber);

        return $allergy;
    }

    /** @param  array<string, mixed>  $data */
    public function updateAllergy(PatientAllergy $allergy, array $data): PatientAllergy
    {
        $this->assertAllergyNotRecorded(Subscriber::withoutGlobalScopes()->findOrFail($allergy->subscriber_id), $data, $allergy->id);
        $allergy->fill($this->allergyAttributes($data))->save();
        $this->mirror($allergy->subscriber_id);

        return $allergy;
    }

    public function removeAllergy(PatientAllergy $allergy): void
    {
        $subscriberId = $allergy->subscriber_id;
        $allergy->delete();
        $this->mirror($subscriberId);
    }

    // ---- proposals (patient) ------------------------------------------------

    /** @param  array<string, mixed>  $data */
    public function propose(Subscriber $subscriber, string $kind, string $action, ?int $targetId, array $data): ProfileProposal
    {
        if ($kind === 'goal') {
            $action = 'edit';
            $targetId = null;
        }

        if (in_array($action, ['edit', 'remove'], true) && $kind !== 'goal') {
            if ($targetId === null) {
                throw new ApiCodeException('Say which item this request is about.', 'proposal_invalid', 422, [], ['target_id' => ['The target_id is required to edit or remove an item.']]);
            }
            $this->target($subscriber, $kind, $targetId);
        }

        $payload = $action === 'remove' ? null : $this->validated($kind, $data);

        if ($kind === 'allergy' && $action !== 'remove') {
            $this->assertAllergyNotRecorded($subscriber, $payload, $action === 'edit' ? $targetId : null);
        }

        $pending = ProfileProposal::query()->where('subscriber_id', $subscriber->id)->where('kind', $kind)->where('status', 'pending')
            ->when($kind !== 'goal' && $action !== 'add', fn ($q) => $q->where('target_id', $targetId))
            ->when($kind === 'allergy' && $action === 'add', fn ($q) => $q->where('action', 'add')->where('payload->group', $payload['group'])
                ->when($payload['group'] === 'other', fn ($q) => $q->where('payload->other_text', $payload['other_text'])))
            ->when($kind === 'medication' && $action === 'add', fn ($q) => $q->where('action', 'add')->where('payload->name', $payload['name']))
            ->exists();

        if ($pending) {
            throw new ApiCodeException('There is already a pending request for this item.', 'proposal_exists', 409);
        }

        return ProfileProposal::create([
            'subscriber_id' => $subscriber->id,
            'kind' => $kind,
            'action' => $action,
            'target_id' => $targetId,
            'payload' => $payload,
        ])->refresh();
    }

    public function withdraw(ProfileProposal $proposal): void
    {
        $this->assertPending($proposal);
        $proposal->forceFill(['status' => 'withdrawn', 'decided_at' => now()])->save();
    }

    public function approve(ProfileProposal $proposal, User $nutritionist, ?string $note): ProfileProposal
    {
        $this->assertPending($proposal);
        $subscriber = Subscriber::withoutGlobalScopes()->findOrFail($proposal->subscriber_id);

        $proposal = DB::transaction(function () use ($proposal, $subscriber, $nutritionist, $note) {
            // B7: re-read under a row lock and checked again, so a double click
            // can't apply the same proposal twice.
            $proposal = $this->lockPending($proposal);

            match ($proposal->kind) {
                'goal' => $this->setGoal($subscriber, $proposal->payload),
                'medication' => match ($proposal->action) {
                    'add' => $this->addMedication($subscriber, $proposal->payload),
                    'edit' => $this->updateMedication($this->freshTarget($subscriber, $proposal), $proposal->payload),
                    'remove' => $this->archiveMedication($this->freshTarget($subscriber, $proposal)),
                },
                'allergy' => match ($proposal->action) {
                    'add' => $this->addAllergy($subscriber, $proposal->payload),
                    'edit' => $this->updateAllergy($this->freshTarget($subscriber, $proposal), $proposal->payload),
                    'remove' => $this->removeAllergy($this->freshTarget($subscriber, $proposal)),
                },
            };

            $this->decide($proposal, 'approved', $nutritionist, $note);

            return $proposal;
        });

        $this->notifyDecision($subscriber, $proposal);

        return $proposal;
    }

    public function reject(ProfileProposal $proposal, User $nutritionist, ?string $note): ProfileProposal
    {
        $this->assertPending($proposal);
        $proposal = DB::transaction(function () use ($proposal, $nutritionist, $note) {
            $proposal = $this->lockPending($proposal);
            $this->decide($proposal, 'rejected', $nutritionist, $note);

            return $proposal;
        });
        $this->notifyDecision(Subscriber::withoutGlobalScopes()->findOrFail($proposal->subscriber_id), $proposal);

        return $proposal;
    }

    /** The proposal re-read with a row lock; 409 proposal_not_pending if it was decided meanwhile. */
    private function lockPending(ProfileProposal $proposal): ProfileProposal
    {
        $locked = ProfileProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
        $this->assertPending($locked);

        return $locked;
    }

    public function hasPendingAllergyProposal(Subscriber $subscriber): bool
    {
        return ProfileProposal::query()->where('subscriber_id', $subscriber->id)->where('kind', 'allergy')->where('status', 'pending')->exists();
    }

    // ---- reading ------------------------------------------------------------

    /** @return list<string> the approved allergy groups (all three classes) */
    public function allergyGroups(Subscriber $subscriber): array
    {
        return PatientAllergy::query()->where('subscriber_id', $subscriber->id)->where('group', '!=', 'other')->pluck('group')->unique()->values()->all();
    }

    /** @return list<string> free-text "other" allergies, for name matching */
    public function otherAllergyTexts(Subscriber $subscriber): array
    {
        return PatientAllergy::query()->where('subscriber_id', $subscriber->id)->where('group', 'other')->pluck('other_text')->filter()->values()->all();
    }

    // ---- legacy arrays (PUT /clients/{id}/health-profile) --------------------

    /**
     * An older client still sending the JSON arrays: match by name, keep
     * every structured field of an item whose name is unchanged, add only new
     * names, remove only names that are really gone. Never a wipe.
     *
     * @param  list<array<string, mixed>>|null  $medications
     * @param  list<string>|null  $allergies
     */
    public function syncLegacyArrays(Subscriber $subscriber, ?array $medications, ?array $allergies): void
    {
        DB::transaction(function () use ($subscriber, $medications, $allergies) {
            if ($medications !== null) {
                $current = PatientMedication::query()->where('subscriber_id', $subscriber->id)->whereNull('archived_at')->get();
                $sent = collect($medications)->filter(fn ($m) => is_array($m) && filled($m['name'] ?? null))
                    ->keyBy(fn ($m) => $this->key($m['name']));

                foreach ($current as $med) {
                    if (! $sent->has($this->key($med->name))) {
                        $med->forceFill(['archived_at' => now()])->save();
                    }
                }
                $known = $current->map(fn ($m) => $this->key($m->name))->all();
                foreach ($sent as $key => $m) {
                    if (! in_array($key, $known, true)) {
                        PatientMedication::create([
                            'subscriber_id' => $subscriber->id,
                            'name' => mb_substr(trim($m['name']), 0, 100),
                            'dose' => filled($m['dose'] ?? null) ? mb_substr((string) $m['dose'], 0, 100) : null,
                            'frequency' => filled($m['schedule'] ?? null) ? mb_substr((string) $m['schedule'], 0, 100) : null,
                        ]);
                    }
                }
            }

            if ($allergies !== null) {
                $current = PatientAllergy::query()->where('subscriber_id', $subscriber->id)->get();
                $sent = collect($allergies)->filter(fn ($a) => is_string($a) && trim($a) !== '')
                    ->mapWithKeys(fn ($a) => [$this->allergyKeyFromText($a) => trim($a)]);

                foreach ($current as $allergy) {
                    if (! $sent->has($this->allergyKey($allergy))) {
                        $allergy->delete();
                    }
                }
                $known = $current->map(fn ($a) => $this->allergyKey($a))->all();
                foreach ($sent as $key => $text) {
                    if (! in_array($key, $known, true)) {
                        $group = AllergyGroups::fromText($text);
                        PatientAllergy::create([
                            'subscriber_id' => $subscriber->id,
                            'group' => $group,
                            'other_text' => $group === 'other' ? mb_substr($text, 0, 100) : null,
                            'class' => 'confirmed_allergy',
                            'note' => $group === 'other' ? null : mb_substr($text, 0, 300),
                        ]);
                    }
                }
            }
        });
    }

    /** Rewrites health_profiles.medications / allergies from the structured records. */
    public function mirror(Subscriber|int $subscriber): void
    {
        $subscriberId = $subscriber instanceof Subscriber ? $subscriber->id : $subscriber;
        $profile = HealthProfile::query()->where('subscriber_id', $subscriberId)->first();

        if ($profile === null) {
            return;
        }

        $profile->forceFill([
            'medications' => PatientMedication::query()->where('subscriber_id', $subscriberId)->whereNull('archived_at')->orderBy('id')->get()
                ->map(fn ($m) => array_filter(['name' => $m->name, 'dose' => $m->dose, 'schedule' => $m->frequency], fn ($v) => $v !== null))->values()->all(),
            'allergies' => PatientAllergy::query()->where('subscriber_id', $subscriberId)->orderBy('id')->get()
                ->map(fn ($a) => $a->group === 'other' ? $a->other_text : ($a->note ?: $a->label()))->values()->all(),
        ])->saveQuietly();
    }

    // ---- internals ------------------------------------------------------------

    private function key(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    private function allergyKey(PatientAllergy $allergy): string
    {
        return $allergy->group === 'other' ? 'other:'.$this->key((string) $allergy->other_text) : $allergy->group;
    }

    private function allergyKeyFromText(string $text): string
    {
        $group = AllergyGroups::fromText($text);

        return $group === 'other' ? 'other:'.$this->key($text) : $group;
    }

    /** @param  array<string, mixed>  $data */
    private function assertAllergyNotRecorded(Subscriber $subscriber, array $data, ?int $exceptId = null): void
    {
        $exists = PatientAllergy::query()->where('subscriber_id', $subscriber->id)->where('group', $data['group'])
            ->when($data['group'] === 'other', fn ($q) => $q->where('other_text', $data['other_text']))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw new ApiCodeException('This allergy is already recorded.', 'already_recorded', 409);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function medicationAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'dose' => $data['dose'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'timing' => $data['timing'] ?? 'any',
            'reason' => $data['reason'] ?? null,
            'status' => $data['status'] ?? 'ongoing',
            'until_date' => ($data['status'] ?? 'ongoing') === 'until' ? ($data['until_date'] ?? null) : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function allergyAttributes(array $data): array
    {
        return [
            'group' => $data['group'],
            'other_text' => $data['group'] === 'other' ? $data['other_text'] : null,
            'class' => $data['class'],
            'note' => $data['note'] ?? null,
        ];
    }

    private function target(Subscriber $subscriber, string $kind, ?int $id): Model
    {
        $model = $kind === 'medication'
            ? PatientMedication::query()->where('subscriber_id', $subscriber->id)->whereNull('archived_at')->find($id)
            : PatientAllergy::query()->where('subscriber_id', $subscriber->id)->find($id);

        abort_if($model === null, 404, 'No such item on this profile.');

        return $model;
    }

    /** The item a proposal points at, unchanged since the proposal was made. */
    private function freshTarget(Subscriber $subscriber, ProfileProposal $proposal): Model
    {
        $model = $proposal->kind === 'medication'
            ? PatientMedication::query()->where('subscriber_id', $subscriber->id)->whereNull('archived_at')->find($proposal->target_id)
            : PatientAllergy::query()->where('subscriber_id', $subscriber->id)->find($proposal->target_id);

        if ($model === null || $model->updated_at->greaterThan($proposal->created_at)) {
            throw new ApiCodeException('The item changed after this request was made. Reject it and ask the patient to send it again.', 'proposal_stale', 409);
        }

        return $model;
    }

    private function assertPending(ProfileProposal $proposal): void
    {
        if ($proposal->status !== 'pending') {
            throw new ApiCodeException('This request has already been decided.', 'proposal_not_pending', 409);
        }
    }

    private function decide(ProfileProposal $proposal, string $status, User $nutritionist, ?string $note): void
    {
        $proposal->forceFill([
            'status' => $status,
            'decided_by' => $nutritionist->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();
    }

    private function notifyDecision(Subscriber $subscriber, ProfileProposal $proposal): void
    {
        $user = $subscriber->user()->first();

        if ($user !== null) {
            // Generic text: no drug or allergy names leave the server in a push.
            $this->notifications->notify($user, 'nutritionist', 'proposal_decided', ['proposal_id' => $proposal->id], "proposals:{$subscriber->id}");
        }
    }
}
