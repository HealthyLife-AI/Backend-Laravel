<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S4-16 / FR-29, BR-11: what a remotely managed client may record alone.
 *
 * The split is by instrument, not by trust. Weight needs a scale;
 * waist, hip, thigh and arm need a tape measure — a remote client has
 * both. Body-fat percentage, muscle mass and water percentage come off a
 * bio-impedance analyser and are absent from these rules on purpose, so a
 * client's guess can never land in the same column as a measured figure
 * (BR-11). They stay on the nutritionist's own endpoint.
 *
 * Was `StoreWeightLogRequest`, which accepted weight only. Renamed with
 * the endpoint in S4-16 — it no longer records just a weight.
 */
class StoreMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Weight is required: every reading is a row in
            // `body_composition_readings`, where `weight_kg` is NOT NULL and
            // the progress chart is a weight series. A tape-only entry
            // (waist, hip, thigh, arm) has to say what the patient weighed
            // that day too; without this it passed validation and failed in
            // the database with a 500.
            'weight_kg' => ['required', 'numeric', 'between:1,500'],
            'waist_cm' => ['nullable', 'numeric', 'between:20,300'],
            'hip_cm' => ['nullable', 'numeric', 'between:20,300'],
            'thigh_cm' => ['nullable', 'numeric', 'between:10,200'],
            'arm_cm' => ['nullable', 'numeric', 'between:10,150'],

            // Optional so an offline entry (S4-12) syncs under the date it
            // was actually taken, not the date it reached the server.
            'recorded_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
