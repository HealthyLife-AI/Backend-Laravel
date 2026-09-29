<?php

namespace App\Services\Progress;

use App\Models\BodyCompositionReading;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use Illuminate\Support\Collection;

/**
 * S4-04 / FR-19: the data behind the progress charts — weight trend,
 * body-composition change, adherence headline and the plan-vs-actual
 * series — in one payload.
 *
 * Shared by the nutritionist's `GET /clients/{id}/progress` and the
 * patient's own `GET /me/progress`, so the two can never drift apart:
 * both return exactly this array for the subscriber they resolved.
 *
 * `change` compares the FIRST and LAST reading in the window rather than
 * against an all-time baseline, so a 30-day view answers "what changed
 * this month". It is null when the window holds fewer than two readings —
 * one reading is a position, not a trend, and reporting a change of 0
 * would imply the client held steady when nothing was actually measured.
 */
class ProgressReportService
{
    public function __construct(private readonly AdherenceService $adherence) {}

    /** @return array<string, mixed> */
    public function build(Subscriber $subscriber, ?string $from, ?string $to): array
    {
        $readings = $subscriber->bodyCompositionReadings()
            ->when($from && $to, fn ($query) => $query->whereBetween('recorded_at', [$from, $to]))
            ->reorder('recorded_at')
            ->get();

        return [
            'weight_trend' => $readings->map(fn (BodyCompositionReading $reading) => [
                'recorded_at' => $reading->recorded_at->toDateString(),
                'weight_kg' => (float) $reading->weight_kg,
                'source' => $reading->source,
            ])->values(),
            'body_composition' => [
                'latest' => $this->snapshot($readings->last()),
                'previous' => $this->snapshot($readings->count() > 1 ? $readings[$readings->count() - 2] : null),
                'change' => $this->change($readings),
            ],
            'adherence' => $this->adherence->summary($subscriber, $from, $to),
            // S4-07: the plan-vs-actual bar chart's series. Shipped in
            // this response rather than behind its own endpoint because
            // one screen renders all of these blocks together.
            'daily_calories' => $this->adherence->dailyCalories($subscriber, $from, $to),
        ];
    }

    /** @return array<string, mixed>|null */
    private function snapshot(?BodyCompositionReading $reading): ?array
    {
        if ($reading === null) {
            return null;
        }

        return [
            'recorded_at' => $reading->recorded_at->toDateString(),
            // BR-13: carried so the charts (S4-18) can mark which figures
            // are analyser-grade and which are the client's own estimate.
            'source' => $reading->source,
            'weight_kg' => (float) $reading->weight_kg,
            'body_fat_percent' => $reading->body_fat_percent !== null ? (float) $reading->body_fat_percent : null,
            'muscle_mass_kg' => $reading->muscle_mass_kg !== null ? (float) $reading->muscle_mass_kg : null,
            'water_percent' => $reading->water_percent !== null ? (float) $reading->water_percent : null,
            'waist_cm' => $reading->waist_cm !== null ? (float) $reading->waist_cm : null,
            'hip_cm' => $reading->hip_cm !== null ? (float) $reading->hip_cm : null,
            'thigh_cm' => $reading->thigh_cm !== null ? (float) $reading->thigh_cm : null,
            'arm_cm' => $reading->arm_cm !== null ? (float) $reading->arm_cm : null,
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

        foreach (['weight_kg', 'body_fat_percent', 'muscle_mass_kg', 'water_percent', 'waist_cm', 'hip_cm', 'thigh_cm', 'arm_cm'] as $metric) {
            if ($first->{$metric} !== null && $last->{$metric} !== null) {
                $change[$metric] = round((float) $last->{$metric} - (float) $first->{$metric}, 1);
            }
        }

        return $change;
    }
}
