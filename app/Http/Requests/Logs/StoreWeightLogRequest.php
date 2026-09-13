<?php

namespace App\Http\Requests\Logs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * S4-02 / FR-17: the CLIENT logging their own periodic weight.
 *
 * Narrower than the nutritionist's `StoreBodyCompositionReadingRequest`
 * against the same table on purpose: a client weighs themselves at home
 * on a bathroom scale. Body fat, muscle mass and water come from a
 * clinic-grade analyser during a visit (FR-10), so they are not accepted
 * from this endpoint at all rather than accepted and left empty — a
 * client cannot supply them, and allowing the fields would let a self-
 * reported guess sit in the same column as a measured reading.
 */
class StoreWeightLogRequest extends FormRequest
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
            'weight_kg' => ['required', 'numeric', 'between:1,500'],
            // Optional so an offline entry (S4-12) syncs under the date it
            // was actually taken, not the date it reached the server.
            'recorded_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }
}
