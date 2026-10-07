<?php

namespace App\Http\Controllers\Api\HealthRecords;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthRecordsResource;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The nutritionist's side: direct edits, medication review, and deciding the patient's proposals. */
class ClientHealthRecordsController extends Controller
{
    public function __construct(private readonly HealthRecordService $records) {}

    public function show(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);

        return response()->json((new HealthRecordsResource($subscriber->load(['patientGoal', 'medications', 'allergyItems', 'healthProfile'])))->resolve($request));
    }

    public function updateGoal(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);
        $this->records->setGoal($subscriber, $this->records->validated('goal', $request->all()));

        return $this->show($subscriber->refresh(), $request);
    }

    public function storeMedication(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);
        $med = $this->records->addMedication($subscriber, $this->records->validated('medication', $request->all()));

        return response()->json(HealthRecordsResource::medication($med), 201);
    }

    public function updateMedication(Subscriber $subscriber, string $medication, Request $request): JsonResponse
    {
        $med = $this->medication($subscriber, $medication);

        return response()->json(HealthRecordsResource::medication($this->records->updateMedication($med, $this->records->validated('medication', $request->all()))));
    }

    public function destroyMedication(Subscriber $subscriber, string $medication): Response
    {
        $this->records->archiveMedication($this->medication($subscriber, $medication));

        return response()->noContent();
    }

    public function reviewMedication(Subscriber $subscriber, string $medication, Request $request): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json(HealthRecordsResource::medication($this->records->reviewMedication($this->medication($subscriber, $medication), $note)));
    }

    public function storeAllergy(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);

        return response()->json(HealthRecordsResource::allergy($this->records->addAllergy($subscriber, $this->records->validated('allergy', $request->all()))), 201);
    }

    public function updateAllergy(Subscriber $subscriber, string $allergy, Request $request): JsonResponse
    {
        $item = $this->allergy($subscriber, $allergy);

        return response()->json(HealthRecordsResource::allergy($this->records->updateAllergy($item, $this->records->validated('allergy', $request->all()))));
    }

    public function destroyAllergy(Subscriber $subscriber, string $allergy): Response
    {
        $this->records->removeAllergy($this->allergy($subscriber, $allergy));

        return response()->noContent();
    }

    public function proposals(Subscriber $subscriber, Request $request): JsonResponse
    {
        $this->own($subscriber);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,withdrawn']])['status'] ?? 'pending';

        return response()->json($subscriber->proposals()->where('status', $status)->orderByDesc('id')->get()->map(fn ($p) => HealthRecordsResource::proposal($p))->values());
    }

    public function approve(Subscriber $subscriber, string $proposal, Request $request): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json(HealthRecordsResource::proposal($this->records->approve($this->proposal($subscriber, $proposal), $request->user(), $note)));
    }

    public function reject(Subscriber $subscriber, string $proposal, Request $request): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        return response()->json(HealthRecordsResource::proposal($this->records->reject($this->proposal($subscriber, $proposal), $request->user(), $note)));
    }

    private function own(Subscriber $subscriber): void
    {
        abort_unless($subscriber->belongsToCaller(), 404);
    }

    private function medication(Subscriber $subscriber, string $id): PatientMedication
    {
        $this->own($subscriber);

        return PatientMedication::query()->where('subscriber_id', $subscriber->id)->whereNull('archived_at')->findOrFail($id);
    }

    private function allergy(Subscriber $subscriber, string $id): PatientAllergy
    {
        $this->own($subscriber);

        return PatientAllergy::query()->where('subscriber_id', $subscriber->id)->findOrFail($id);
    }

    private function proposal(Subscriber $subscriber, string $id): ProfileProposal
    {
        $this->own($subscriber);

        return ProfileProposal::query()->where('subscriber_id', $subscriber->id)->findOrFail($id);
    }
}
