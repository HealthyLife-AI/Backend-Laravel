<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Concerns\RespondsWithNull;
use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Progress\GoalProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 2, C: the goal projection. The nutritionist sees it always, with the
 * inputs behind it, and decides per patient whether the patient does
 * (default off). The patient's own endpoint answers null while it is hidden.
 */
class GoalProjectionController extends Controller
{
    use RespondsWithNull;

    public function __construct(private readonly GoalProjection $projection) {}

    /** `{ visible_to_patient, projection }`; projection is null when there is nothing to project. */
    public function show(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json([
            'visible_to_patient' => (bool) $subscriber->show_goal_projection,
            'projection' => $this->projection->for($subscriber),
        ]);
    }

    public function visibility(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $visible = $request->validate(['visible' => ['required', 'boolean']])['visible'];

        $subscriber->forceFill(['show_goal_projection' => $visible])->save();

        return response()->json(['visible_to_patient' => (bool) $visible]);
    }

    /** The patient's own copy, or the JSON literal `null`. */
    public function mine(): Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $this->objectOrNull($this->projection->forPatient($subscriber));
    }
}
