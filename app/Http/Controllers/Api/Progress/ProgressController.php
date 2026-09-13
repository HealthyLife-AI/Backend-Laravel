<?php

namespace App\Http\Controllers\Api\Progress;

use App\Http\Controllers\Controller;
use App\Http\Requests\Progress\DateWindowRequest;
use App\Models\BodyCompositionReading;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

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
    public function __construct(private readonly AdherenceService $adherence) {}

    public function show(Subscriber $subscriber, DateWindowRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        $readings = $subscriber->bodyCompositionReadings()
            ->when($from && $to, fn ($query) => $query->whereBetween('recorded_at', [$from, $to]))
            ->reorder('recorded_at')
            ->get();

        return response()->json([
            'weight_trend' => $readings->map(fn (BodyCompositionReading $reading) => [
                'recorded_at' => $reading->recorded_at->toDateString(),
                'weight_kg' => (float) $reading->weight_kg,
            ])->values(),
            'body_composition' => [
                'latest' => $this->snapshot($readings->last()),
                'previous' => $this->snapshot($readings->count() > 1 ? $readings[$readings->count() - 2] : null),
                'change' => $this->change($readings),
            ],
            'adherence' => $this->adherence->summary($subscriber, $from, $to),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function snapshot(?BodyCompositionReading $reading): ?array
    {
        if ($reading === null) {
            return null;
        }

        return [
            'recorded_at' => $reading->recorded_at->toDateString(),
            'weight_kg' => (float) $reading->weight_kg,
            'body_fat_percent' => $reading->body_fat_percent !== null ? (float) $reading->body_fat_percent : null,
            'muscle_mass_kg' => $reading->muscle_mass_kg !== null ? (float) $reading->muscle_mass_kg : null,
            'water_percent' => $reading->water_percent !== null ? (float) $reading->water_percent : null,
            'waist_cm' => $reading->waist_cm !== null ? (float) $reading->waist_cm : null,
        ];
    }

    /**
     * First-to-last delta per metric, skipping any metric that isn't
     * present at both ends — a client analysed once at the clinic and
     * self-weighing since has weight at both ends but body fat only at
     * one, and subtracting from null would report a fabricated loss.
     *
     * @param  Collection<int, BodyCompositionReading>  $readings
     * @return array<string, float>|null
     */
    private function change($readings): ?array
    {
        if ($readings->count() < 2) {
            return null;
        }

        $first = $readings->first();
        $last = $readings->last();
        $change = [];

        foreach (['weight_kg', 'body_fat_percent', 'muscle_mass_kg', 'water_percent', 'waist_cm'] as $metric) {
            if ($first->{$metric} !== null && $last->{$metric} !== null) {
                $change[$metric] = round((float) $last->{$metric} - (float) $first->{$metric}, 1);
            }
        }

        return $change;
    }
}
