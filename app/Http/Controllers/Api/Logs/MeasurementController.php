<?php

namespace App\Http\Controllers\Api\Logs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\StoreMeasurementRequest;
use App\Http\Resources\BodyCompositionReadingResource;
use App\Models\BodyCompositionReading;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * S4-16 / FR-29, BR-11, BR-13, logs.manage.own: a remotely managed client
 * recording their own weight and circumferences.
 *
 * Was `WeightLogController` at `POST /me/weight-logs`. Renamed together
 * with the route in S4-16: once the endpoint accepts four circumference
 * fields as well, "weight-log" names something it no longer is. No
 * consumer had been built yet, so the rename cost nothing now and would
 * have cost a client migration after S4-11/S4-17.
 *
 * Writes to the same `body_composition_readings` table the nutritionist
 * writes to, so the progress chart (S4-04) reads one series — with
 * `source` recording which instrument produced each row (BR-13), rather
 * than the two being silently mixed.
 *
 * Idempotent by construction (S4-05): the table holds one reading per
 * date, so a replayed offline entry updates that day's row instead of
 * appending a duplicate. The date is the key; no idempotency token
 * needed.
 */
class MeasurementController extends Controller
{
    public function store(StoreMeasurementRequest $request): JsonResponse
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $recordedAt = $request->date('recorded_at')?->toDateString() ?? now()->toDateString();

        // Only the fields BR-11 allows a client to submit. Taken from the
        // model's own list rather than re-typed here, so the rule has one
        // definition — anything not on it (body-fat, muscle mass, water)
        // is dropped even if a caller sends it.
        $measurements = $request->safe()->only(BodyCompositionReading::CLIENT_MEASURABLE);

        // `whereDate`, not an equality match on the cast `date` column:
        // the string Eloquent writes is not byte-identical across drivers,
        // so equality finds the row on MySQL and misses it on SQLite —
        // appending a duplicate in tests while passing in production.
        $reading = $subscriber->bodyCompositionReadings()
            ->whereDate('recorded_at', $recordedAt)
            ->first();

        if ($reading === null) {
            $reading = $subscriber->bodyCompositionReadings()->create($measurements + [
                'recorded_at' => $recordedAt,
                'source' => BodyCompositionReading::SOURCE_SELF,
            ]);

            return (new BodyCompositionReadingResource($reading))->response()->setStatusCode(201);
        }

        // A client correcting their own entry keeps it self-reported. A
        // client re-sending a day the nutritionist already measured in
        // clinic must NOT downgrade that row's source to self-reported —
        // the analyser figures on it stay analyser figures, so `source`
        // is left as it is rather than re-stamped.
        $reading->update($measurements);

        return (new BodyCompositionReadingResource($reading))->response();
    }
}
