<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\DateWindowRequest;
use App\Models\Subscriber;
use App\Services\Progress\ProgressReportService;
use Illuminate\Http\JsonResponse;

/**
 * S4-04 / FR-19, progress.view: the data behind the client-profile
 * charts — weight trend, body-composition change, and the adherence
 * headline, in one response.
 *
 * One endpoint rather than three because the Client Profile & Progress
 * screen (S4-07) renders them on a single view; three round-trips to
 * paint one screen is the thing NFR-01 is trying to avoid.
 *
 * `change` compares the FIRST and LAST reading in the window rather than
 * against an all-time baseline, so a 30-day view answers "what changed
 * this month", which is the question the screen is asking. It is null
 * when the window holds fewer than two readings — one reading is a
 * position, not a trend, and reporting a change of 0 would imply the
 * client held steady when nothing was actually measured.
 */
class ProgressController extends Controller
{
    public function __construct(private readonly ProgressReportService $report) {}

    public function show(Subscriber $subscriber, DateWindowRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json($this->report->build(
            $subscriber,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }
}
