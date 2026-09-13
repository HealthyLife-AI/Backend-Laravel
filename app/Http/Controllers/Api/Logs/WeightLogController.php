<?php

namespace App\Http\Controllers\Api\Logs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\StoreWeightLogRequest;
use App\Http\Resources\BodyCompositionReadingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * S4-02 / FR-17, logs.manage.own: the client logging their own weight.
 *
 * Writes to the EXISTING `body_composition_readings` table from Sprint 2
 * rather than a second weight table, so the nutritionist's progress chart
 * (S4-04) reads one series whether a number came from a clinic analyser
 * or the client's own scale.
 *
 * Idempotent by construction (S4-05): the table holds one reading per
 * date, so re-sending the same day's weight — which is exactly what an
 * offline mobile queue does on retry — updates that day's row instead of
 * appending a duplicate. No idempotency key needed for this endpoint;
 * the date IS the key.
 */
class WeightLogController extends Controller
{
    public function store(StoreWeightLogRequest $request): JsonResponse
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $recordedAt = $request->date('recorded_at')?->toDateString() ?? now()->toDateString();
        $weight = $request->float('weight_kg');

        // `whereDate`, not `updateOrCreate(['recorded_at' => $date])`:
        // `recorded_at` is a DATE column carrying a `date` cast, and the
        // string Eloquent writes for it is not byte-identical across
        // drivers — an equality match finds the existing row on MySQL and
        // misses it on SQLite, appending a duplicate instead of updating.
        // Since tests run SQLite and production runs MySQL, the equality
        // form works everywhere it is checked and breaks where it is not.
        $reading = $subscriber->bodyCompositionReadings()
            ->whereDate('recorded_at', $recordedAt)
            ->first();

        if ($reading === null) {
            $reading = $subscriber->bodyCompositionReadings()->create([
                'recorded_at' => $recordedAt,
                'weight_kg' => $weight,
            ]);

            return (new BodyCompositionReadingResource($reading))->response()->setStatusCode(201);
        }

        $reading->update(['weight_kg' => $weight]);

        return (new BodyCompositionReadingResource($reading))->response();
    }
}
