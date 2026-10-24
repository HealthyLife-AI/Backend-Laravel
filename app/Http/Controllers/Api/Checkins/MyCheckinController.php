<?php

namespace App\Http\Controllers\Api\Checkins;

use App\Http\Controllers\Concerns\RespondsWithNull;
use App\Http\Controllers\Controller;
use App\Models\PatientCheckin;
use App\Models\Subscriber;
use App\Services\Checkins\CheckinQuestionnaire;
use App\Services\Checkins\CheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 2, A5: the patient's side. No route-bound subscriber (it comes from
 * the token); a check-in is looked up among the caller's own only, so another
 * patient's id is a 404. What the nutritionist concluded (flagged, reviewed)
 * is never shown here.
 */
class MyCheckinController extends Controller
{
    use RespondsWithNull;

    public function __construct(
        private readonly CheckinService $checkins,
        private readonly CheckinQuestionnaire $questionnaire,
    ) {}

    /** { id, due_on, questions: [...] } or the JSON literal `null`. */
    public function current(): Response
    {
        $checkin = $this->checkins->current($this->subscriber());

        return $this->objectOrNull($checkin === null ? null : [
            'id' => $checkin->id,
            'due_on' => $checkin->due_on->toDateString(),
            'questions' => $this->questionnaire->forPatient(),
        ]);
    }

    public function submit(string $id, Request $request): JsonResponse
    {
        $checkin = PatientCheckin::query()->where('subscriber_id', $this->subscriber()->id)->findOrFail($id);

        $answers = $request->validate(['answers' => ['required', 'array']])['answers'];

        return response()->json($this->present($this->checkins->submit($checkin, $answers)), 201);
    }

    /** Own history, newest first, the last 10. */
    public function index(): JsonResponse
    {
        return response()->json(
            $this->subscriber()->checkins()->limit(10)->get()->map(fn (PatientCheckin $c) => $this->present($c))->values(),
        );
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }

    /** @return array<string, mixed> */
    private function present(PatientCheckin $c): array
    {
        return [
            'id' => $c->id,
            'due_on' => $c->due_on->toDateString(),
            'status' => $c->status,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'answers' => $c->answers,
        ];
    }
}
