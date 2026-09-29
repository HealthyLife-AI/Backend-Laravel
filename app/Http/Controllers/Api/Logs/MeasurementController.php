<?php

namespace App\Http\Controllers\Api\Logs;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\StoreMeasurementRequest;
use App\Http\Requests\Progress\DateWindowRequest;
use App\Http\Resources\BodyCompositionReadingResource;
use App\Models\BodyCompositionReading;
use App\Services\Logs\LogWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
    public function __construct(private readonly LogWindow $window) {}

    /**
     * The patient's own full series — clinic and self-reported readings,
     * every field plus `source` — oldest first, for their history screen.
     * A plain array, not paginated, like the nutritionist's list: a
     * client's readings are one row a day at most.
     */
    public function index(DateWindowRequest $request): AnonymousResourceCollection
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $readings = $subscriber->bodyCompositionReadings()
            ->when(
                $request->filled('from') && $request->filled('to'),
                fn ($query) => $query->whereDate('recorded_at', '>=', $request->string('from')->toString())
                    ->whereDate('recorded_at', '<=', $request->string('to')->toString()),
            )
            // The relation is newest-first by default; this list is oldest-first.
            ->reorder('recorded_at')
            ->orderBy('id')
            ->get();

        return BodyCompositionReadingResource::collection($readings);
    }

    /**
     * BR-15: delete one of the patient's OWN self-reported readings while
     * it is inside the edit window. A clinic reading is the nutritionist's
     * record and is never the patient's to delete. Looked up among the
     * caller's readings only, so another patient's id is a 404.
     */
    public function destroy(string $id): Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        $reading = $subscriber->bodyCompositionReadings()->findOrFail($id);

        if ($reading->source !== BodyCompositionReading::SOURCE_SELF) {
            throw new ApiCodeException('A reading taken at the clinic can only be removed by your nutritionist.', 'reading_not_deletable', 403);
        }

        $this->window->assertReadingEditable($reading->recorded_at);

        $reading->delete();

        return response()->noContent();
    }

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
            // BR-19: a new reading dated beyond the late threshold is
            // accepted and marked late; only one beyond the rejection limit
            // is refused. Only a NEW one — re-sending a day that is already
            // saved is a replay and is handled below.
            $date = $request->date('recorded_at') ?? now();
            $this->window->assertDateNotTooOld($date, 'recorded_at');

            $reading = $subscriber->bodyCompositionReadings()->create($measurements + [
                'recorded_at' => $recordedAt,
                'source' => BodyCompositionReading::SOURCE_SELF,
                'is_late' => $this->window->isDateLate($date),
            ]);

            return (new BodyCompositionReadingResource($reading))->response()->setStatusCode(201);
        }

        // BR-15: past the edit window the day's figures are locked, or
        // deleting would be pointless (re-sending the day would rewrite it).
        // Re-sending the SAME figures is a replay of an entry that was
        // saved, and is answered like one instead of as an error.
        if ($this->window->isReadingLocked($reading->recorded_at)) {
            if ($this->sameFigures($reading, $measurements)) {
                return (new BodyCompositionReadingResource($reading))->response();
            }

            $this->window->throwLocked();
        }

        // A client correcting their own entry keeps it self-reported. A
        // client re-sending a day the nutritionist already measured in
        // clinic must NOT downgrade that row's source to self-reported —
        // the analyser figures on it stay analyser figures, so `source`
        // is left as it is rather than re-stamped.
        $reading->update($measurements);

        return (new BodyCompositionReadingResource($reading))->response();
    }

    /** @param  array<string, mixed>  $measurements */
    private function sameFigures(BodyCompositionReading $reading, array $measurements): bool
    {
        foreach ($measurements as $field => $value) {
            if ($value !== null && (float) $reading->{$field} !== (float) $value) {
                return false;
            }
        }

        return true;
    }
}
