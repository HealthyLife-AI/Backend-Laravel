<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\DateWindowRequest;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use Illuminate\Http\JsonResponse;

/**
 * S4-03 / FR-18, progress.view: plan-vs-actual for one client.
 *
 * `$subscriber` is route-model-bound, so ownership is re-checked here —
 * see Subscriber::belongsToCaller(). `progress.view` is held by both
 * nutritionist and client roles, and the scope on `Subscriber` already
 * narrows a client to their own nutritionist's roster; the
 * `belongsToCaller()` check is what stops a nutritionist reading a peer's
 * client, and returns 404 rather than 403 so the id isn't confirmed.
 */
class AdherenceController extends Controller
{
    public function __construct(private readonly AdherenceService $adherence) {}

    public function show(Subscriber $subscriber, DateWindowRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json(
            $this->adherence->summary(
                $subscriber,
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
            )
        );
    }
}
