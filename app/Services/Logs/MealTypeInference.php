<?php

namespace App\Services\Logs;

use App\Models\MealLog;
use Carbon\CarbonInterface;

/**
 * BR-16: which meal an off-plan log belongs to when the patient didn't say.
 *
 * Read from the wall-clock time of `$at` in its OWN timezone — the caller
 * passes logged_at as parsed from the request, so an offset the app sent is
 * kept, and a time without one is in the app timezone. The ranges come from
 * config('patient_app.meal_times'); a time in none of them is a snack.
 */
class MealTypeInference
{
    public function infer(CarbonInterface $at): string
    {
        $minute = $at->hour * 60 + $at->minute;

        foreach ((array) config('patient_app.meal_times') as $meal => $range) {
            [$from, $to] = $this->parseRange((string) $range);

            if ($minute >= $from && $minute <= $to) {
                return $meal;
            }
        }

        return 'snack';
    }

    /**
     * An explicit meal type, tolerating case and surrounding spaces
     * ("Lunch " is lunch). Anything else is null, and the caller infers.
     */
    public function normalise(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, MealLog::MEAL_TYPES, true) ? $value : null;
    }

    /** @return array{0: int, 1: int} minutes since midnight, inclusive */
    private function parseRange(string $range): array
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $range, $m)) {
            return [1, 0]; // malformed: matches nothing
        }

        return [(int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]];
    }
}
