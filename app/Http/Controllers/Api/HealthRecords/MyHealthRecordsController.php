<?php

namespace App\Http\Controllers\Api\HealthRecords;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthRecordsResource;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** The patient reads their approved goal / medications / allergies and proposes changes. */
class MyHealthRecordsController extends Controller
{
    public function __construct(private readonly HealthRecordService $records) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json((new HealthRecordsResource($this->subscriber()->load(['patientGoal', 'medications', 'allergyItems', 'healthProfile'])))->resolve($request));
    }

    public function propose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(ProfileProposal::KINDS)],
            'action' => ['required', Rule::in(ProfileProposal::ACTIONS)],
            'target_id' => ['nullable', 'integer'],
            'data' => ['nullable', 'array'],
        ]);

        $proposal = $this->records->propose($this->subscriber(), $data['kind'], $data['action'], $data['target_id'] ?? null, $data['data'] ?? []);

        return response()->json(HealthRecordsResource::proposal($proposal), 201);
    }

    public function withdraw(string $proposal): Response
    {
        $this->records->withdraw(ProfileProposal::query()->where('subscriber_id', $this->subscriber()->id)->findOrFail($proposal));

        return response()->noContent();
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;
        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
