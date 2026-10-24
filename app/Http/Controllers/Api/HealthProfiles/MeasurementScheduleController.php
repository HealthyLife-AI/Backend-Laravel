<?php

namespace App\Http\Controllers\Api\HealthProfiles;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Services\Measurements\MeasurementScheduleService;
use App\Support\ClinicDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 1, B: the nutritionist's measurement schedule for one patient
 * (owner only; writes carry the follow-up gate in the routes), and the
 * patient's read-only view of their own.
 */
class MeasurementScheduleController extends Controller
{
    public function __construct(private readonly MeasurementScheduleService $schedules) {}

    /** The schedule, or the JSON literal `null` when none is set. */
    public function show(Subscriber $subscriber): Response
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return $this->nullable($this->present($subscriber->measurementSchedule, true));
    }

    public function update(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate([
            'interval_days' => ['required', 'integer', 'between:'.SubscriberMeasurementSchedule::MIN_DAYS.','.SubscriberMeasurementSchedule::MAX_DAYS],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail) {
                // The clinic's today, not the server's.
                if ($value !== null && $value < ClinicDay::today()->toDateString()) {
                    $fail('The next due date cannot be in the past.');
                }
            }],
        ]);

        $schedule = $this->schedules->set($subscriber, (int) $data['interval_days'], $data['next_due_on'] ?? null, $request->user());

        return response()->json($this->present($schedule, true));
    }

    public function destroy(Subscriber $subscriber): Response
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $this->schedules->forget($subscriber);

        return response()->noContent();
    }

    /**
     * GET /me/measurement-schedule: `{ interval_days, next_due_on }` or `null`.
     * Nothing else of the nutritionist's bookkeeping is shown to the patient.
     */
    public function mine(): Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $this->nutritionistless($subscriber->measurementSchedule);
    }

    private function nutritionistless(?SubscriberMeasurementSchedule $schedule): Response
    {
        return $this->nullable($this->present($schedule, false));
    }

    /**
     * `response()->json(null)` would send `{}` (Laravel turns null into an empty
     * object), which a mobile client cannot tell from a schedule. The literal
     * `null` is what the contract says for "none set".
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function nullable(?array $payload): Response
    {
        return response(json_encode($payload), 200, ['Content-Type' => 'application/json']);
    }

    /** @return array<string, mixed>|null */
    private function present(?SubscriberMeasurementSchedule $schedule, bool $forNutritionist): ?array
    {
        if ($schedule === null) {
            return null;
        }

        $base = ['interval_days' => $schedule->interval_days, 'next_due_on' => $schedule->next_due_on->toDateString()];

        if (! $forNutritionist) {
            return $base;
        }

        $days = $this->schedules->daysOverdue($schedule);

        return $base + [
            'is_overdue' => $days > 0,
            'days_overdue' => $days,
            'last_reminded_on' => $schedule->last_reminded_on?->toDateString(),
        ];
    }
}
