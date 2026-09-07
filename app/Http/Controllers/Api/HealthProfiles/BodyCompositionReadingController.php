<?php

namespace App\Http\Controllers\Api\HealthProfiles;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthProfiles\StoreBodyCompositionReadingRequest;
use App\Http\Resources\BodyCompositionReadingResource;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FR-10: body-composition history, one row per visit — never overwritten.
 * `$subscriber` is route-model-bound but not thereby isolated on its own
 * — see Subscriber::belongsToCaller() — so ownership is re-checked here.
 */
class BodyCompositionReadingController extends Controller
{
    public function index(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return BodyCompositionReadingResource::collection($subscriber->bodyCompositionReadings);
    }

    public function store(Subscriber $subscriber, StoreBodyCompositionReadingRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $reading = $subscriber->bodyCompositionReadings()->create($request->validated());

        return (new BodyCompositionReadingResource($reading))->response()->setStatusCode(201);
    }
}
