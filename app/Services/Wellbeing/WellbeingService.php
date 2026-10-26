<?php

namespace App\Services\Wellbeing;

use App\Exceptions\ApiCodeException;
use App\Models\PatientWellbeingLog;
use App\Models\Subscriber;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;

/**
 * Phase 4, C: the patient's daily sleep / stress / mood log. Days are the
 * clinic's (ClinicDay), like everywhere else a "day" decides something.
 */
class WellbeingService
{
    /** Stress and mood are listed worst first: a tie in "most frequent" goes to the worse one. */
    private const STRESS_ORDER = ['high', 'medium', 'low'];

    private const MOOD_ORDER = ['low', 'ok', 'good'];

    /**
     * Today or one of the 7 days before it; a future date is refused.
     *
     * @param  array<string, mixed>  $data  sleep_hours, sleep_quality, stress, mood, note (at least one set)
     */
    public function upsert(Subscriber $subscriber, string $date, array $data): PatientWellbeingLog
    {
        $day = ClinicDay::date($date);
        $today = ClinicDay::today();

        if ($day->greaterThan($today)) {
            throw new ApiCodeException('A wellbeing entry cannot be dated in the future.', 'wellbeing_date_future', 422);
        }

        if ($day->lessThan($today->subDays(PatientWellbeingLog::MAX_AGE_DAYS))) {
            throw new ApiCodeException('A wellbeing entry can be added for today or the last '.PatientWellbeingLog::MAX_AGE_DAYS.' days only.', 'wellbeing_date_too_old', 422);
        }

        // A day's entry is replaced as a whole: a field left out is cleared.
        $values = [
            'sleep_hours' => $data['sleep_hours'] ?? null,
            'sleep_quality' => $data['sleep_quality'] ?? null,
            'stress' => $data['stress'] ?? null,
            'mood' => $data['mood'] ?? null,
            'note' => isset($data['note']) ? trim((string) $data['note']) : null,
        ];

        // whereDate: a date column may carry a time part on some drivers (SQLite).
        $log = PatientWellbeingLog::query()->where('subscriber_id', $subscriber->id)->whereDate('log_date', $day->toDateString())->first()
            ?? new PatientWellbeingLog(['subscriber_id' => $subscriber->id, 'log_date' => $day->toDateString()]);

        $log->fill($values)->save();

        return $log;
    }

    public function find(Subscriber $subscriber, string $date): ?PatientWellbeingLog
    {
        return PatientWellbeingLog::query()->where('subscriber_id', $subscriber->id)->whereDate('log_date', ClinicDay::date($date)->toDateString())->first();
    }

    /**
     * Entries between two dates, oldest first. Default: the last 14 days. A
     * range of more than 60 days, or one that ends before it starts, is refused.
     *
     * @return \Illuminate\Support\Collection<int, PatientWellbeingLog>
     */
    public function range(Subscriber $subscriber, ?string $from, ?string $to)
    {
        $end = $to !== null ? ClinicDay::date($to) : ClinicDay::today();
        $start = $from !== null ? ClinicDay::date($from) : $end->subDays(PatientWellbeingLog::SUMMARY_DAYS - 1);

        if ($start->greaterThan($end)) {
            throw new ApiCodeException('"from" must not be after "to".', 'wellbeing_range_invalid', 422);
        }

        if ($start->diffInDays($end) + 1 > PatientWellbeingLog::MAX_RANGE_DAYS) {
            throw new ApiCodeException('A range is at most '.PatientWellbeingLog::MAX_RANGE_DAYS.' days.', 'wellbeing_range_too_long', 422);
        }

        return PatientWellbeingLog::query()->where('subscriber_id', $subscriber->id)
            ->whereDate('log_date', '>=', $start->toDateString())->whereDate('log_date', '<=', $end->toDateString())
            ->orderBy('log_date')->get();
    }

    /**
     * The nutritionist's 14 days up to today: average sleep, nights under the
     * short-sleep line, and the most frequent stress and mood (a tie goes to
     * the worse one).
     *
     * @return array<string, mixed>
     */
    public function summary(Subscriber $subscriber): array
    {
        $end = ClinicDay::today();
        $start = $end->subDays(PatientWellbeingLog::SUMMARY_DAYS - 1);
        $logs = $this->range($subscriber, $start->toDateString(), $end->toDateString());

        $hours = $logs->pluck('sleep_hours')->filter(fn ($h) => $h !== null)->map(fn ($h) => (float) $h);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'days' => PatientWellbeingLog::SUMMARY_DAYS,
            'logged_days' => $logs->count(),
            'sleep_logged_nights' => $hours->count(),
            'avg_sleep_hours' => $hours->isEmpty() ? null : round($hours->avg(), 1),
            'nights_under_6h' => $hours->filter(fn ($h) => $h < $this->shortSleepHours())->count(),
            'top_stress' => $this->mostFrequent($logs->pluck('stress')->filter()->all(), self::STRESS_ORDER),
            'top_mood' => $this->mostFrequent($logs->pluck('mood')->filter()->all(), self::MOOD_ORDER),
        ];
    }

    /** The short-sleep line, in hours: a night under it is a "short night". */
    public function shortSleepHours(): float
    {
        return (float) config('weight_insight.short_sleep_hours');
    }

    /**
     * Nights under the line between two clinic days (both inclusive); the facts
     * of the weight insight use this for its `short_sleep` boolean.
     */
    public function shortNights(Subscriber $subscriber, string $from, string $to): int
    {
        return PatientWellbeingLog::query()->where('subscriber_id', $subscriber->id)
            ->whereDate('log_date', '>=', $from)->whereDate('log_date', '<=', $to)
            ->whereNotNull('sleep_hours')->where('sleep_hours', '<', $this->shortSleepHours())->count();
    }

    /** Has the patient logged anything in the last N days (today included)? */
    public function loggedWithin(Subscriber $subscriber, int $days, ?CarbonImmutable $today = null): bool
    {
        $today ??= ClinicDay::today();

        return PatientWellbeingLog::query()->where('subscriber_id', $subscriber->id)
            ->whereDate('log_date', '>=', $today->subDays($days)->toDateString())->exists();
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $worstFirst
     */
    private function mostFrequent(array $values, array $worstFirst): ?string
    {
        if ($values === []) {
            return null;
        }

        $counts = array_count_values($values);
        $best = null;

        foreach ($worstFirst as $candidate) {
            if (isset($counts[$candidate]) && ($best === null || $counts[$candidate] > $counts[$best])) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
