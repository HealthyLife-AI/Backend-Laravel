<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\DateWindowRequest;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use App\Services\Progress\ProgressReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * FR-18, FR-19, progress.view: the patient reading their OWN adherence and
 * progress.
 *
 * `GET /clients/{subscriber}/adherence|progress` can never serve a patient:
 * they are route-bound and `belongsToCaller()` compares the subscriber's
 * nutritionist to the caller, which is never true for a client token (they
 * answered 404 to the very role the permission matrix gave `progress.view`).
 * These follow the patient-scoped convention instead — the subscriber is
 * resolved from the token, never from the URL — and return the same bodies
 * the nutritionist sees for that patient.
 */
class OwnProgressController extends Controller
{
    public function __construct(
        private readonly AdherenceService $adherence,
        private readonly ProgressReportService $report,
    ) {}

    public function adherence(DateWindowRequest $request): JsonResponse
    {
        return response()->json($this->adherence->summary(
            $this->subscriber(),
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    public function progress(DateWindowRequest $request): JsonResponse
    {
        return response()->json($this->report->build(
            $this->subscriber(),
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
