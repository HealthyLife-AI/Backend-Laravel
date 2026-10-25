<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Concerns\RespondsWithNull;
use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Progress\WeightInsightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 3, C: the weight-change insight. The nutritionist always sees it, with
 * the facts it was built from, and decides per patient whether the patient does
 * (default off). The patient's own endpoint answers the JSON literal `null`
 * while it is hidden or there is nothing to explain yet.
 */
class WeightInsightController extends Controller
{
    use RespondsWithNull;

    public function __construct(private readonly WeightInsightService $insights) {}

    /** `{ visible_to_patient, insight }`; insight is null with fewer than two usable readings. */
    public function show(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return $this->respond($subscriber, $this->insights->ensure($subscriber));
    }

    /** A fresh wording for the same readings. Throttled like the plan summary suggestion. */
    public function regenerate(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return $this->respond($subscriber, $this->insights->regenerate($subscriber));
    }

    public function visibility(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $visible = $request->validate(['visible' => ['required', 'boolean']])['visible'];

        $subscriber->forceFill(['show_weight_insight' => $visible])->save();

        return response()->json(['visible_to_patient' => (bool) $visible]);
    }

    /** The patient's own copy, or the JSON literal `null`. */
    public function mine(): Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $this->objectOrNull($this->insights->forPatient($subscriber));
    }

    private function respond(Subscriber $subscriber, $insight): JsonResponse
    {
        return response()->json([
            'visible_to_patient' => (bool) $subscriber->show_weight_insight,
            'insight' => $insight === null ? null : $this->insights->present($insight),
        ]);
    }
}
