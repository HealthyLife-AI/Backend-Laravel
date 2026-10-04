<?php

namespace App\Http\Controllers\Api\Logs;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MealLogResource;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The nutritionist's view of a patient's meal log, day by day: every log
 * with its kind (planned / alternative / off_plan, fixed when it was made),
 * meal type and the late / edited marks, plus the day's planned vs logged
 * calories — the same totals the progress chart uses.
 */
class ClientMealLogController extends Controller
{
    private const DEFAULT_DAYS = 7;

    private const MAX_DAYS = 31;

    public function __construct(private readonly AdherenceService $adherence) {}

    public function daily(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new ApiCodeException(
                'The range may be at most '.self::MAX_DAYS.' days.',
                'range_too_large',
                422,
                ['max_days' => self::MAX_DAYS],
                ['from' => ['The range may be at most '.self::MAX_DAYS.' days.']],
            );
        }

        $logs = $subscriber->mealLogs()
            ->loggedBetween($from->toDateString(), $to->toDateString())
            ->with('food')
            ->reorder('logged_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (MealLog $log) => $log->logged_at->toDateString());

        $totals = collect($this->adherence->dailyCalories($subscriber, $from->toDateString(), $to->toDateString()))->keyBy('date');

        $days = [];
        for ($day = $to; $day->greaterThanOrEqualTo($from); $day = $day->subDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'planned_calories' => $totals[$date]['planned_calories'] ?? null,
                'logged_calories' => $totals[$date]['logged_calories'] ?? 0.0,
                'logs' => MealLogResource::collection($logs->get($date, collect()))->resolve($request),
            ];
        }

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days]);
    }
}
