<?php

namespace App\Http\Controllers\Api\Checkins;

use App\Http\Controllers\Concerns\RespondsWithNull;
use App\Http\Controllers\Controller;
use App\Models\PatientCheckin;
use App\Models\Subscriber;
use App\Models\SubscriberCheckinSetting;
use App\Services\Checkins\CheckinQuestionnaire;
use App\Services\Checkins\CheckinService;
use App\Support\ClinicDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Phase 2, A3: the nutritionist's side of the periodic check-in: the
 * schedule, the answers history, and marking one reviewed. Owner only;
 * writes carry the follow-up gate in the routes.
 */
class ClientCheckinController extends Controller
{
    use RespondsWithNull;

    public function __construct(
        private readonly CheckinService $checkins,
        private readonly CheckinQuestionnaire $questionnaire,
    ) {}

    /** The settings, or the JSON literal `null` when none were ever set. */
    public function show(Subscriber $subscriber): Response
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return $this->objectOrNull($this->presentSetting($subscriber->checkinSetting));
    }

    public function update(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate([
            // null = off.
            'interval_days' => ['present', 'nullable', 'integer', Rule::in(SubscriberCheckinSetting::INTERVALS)],
            'next_due_on' => ['nullable', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail) {
                // The clinic's today, not the server's.
                if ($value !== null && $value < ClinicDay::today()->toDateString()) {
                    $fail('The next due date cannot be in the past.');
                }
            }],
        ]);

        $setting = $this->checkins->configure($subscriber, $data['interval_days'], $data['next_due_on'] ?? null, $request->user());

        return response()->json($this->presentSetting($setting));
    }

    public function destroy(Subscriber $subscriber): Response
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        SubscriberCheckinSetting::query()->where('subscriber_id', $subscriber->id)->delete();

        return response()->noContent();
    }

    /** The last 20 check-ins, newest first, answers in the clinic's own words. */
    public function history(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json(
            $subscriber->checkins()->limit(20)->get()->map(fn (PatientCheckin $c) => $this->presentCheckin($c))->values(),
        );
    }

    public function review(Subscriber $subscriber, string $checkin, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $model = PatientCheckin::query()->where('subscriber_id', $subscriber->id)->findOrFail($checkin);

        return response()->json($this->presentCheckin($this->checkins->review($model, $request->user())));
    }

    /** @return array<string, mixed>|null */
    private function presentSetting(?SubscriberCheckinSetting $setting): ?array
    {
        return $setting === null ? null : [
            'interval_days' => $setting->interval_days,
            'next_due_on' => $setting->next_due_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCheckin(PatientCheckin $c): array
    {
        return [
            'id' => $c->id,
            'due_on' => $c->due_on->toDateString(),
            'status' => $c->status,
            'submitted_at' => $c->submitted_at?->toIso8601String(),
            'flagged' => $c->flagged,
            'reviewed_at' => $c->reviewed_at?->toIso8601String(),
            'answers' => $c->answers,
            'answers_display' => $this->questionnaire->display($c->answers),
            'questionnaire_version' => $c->questionnaire_version,
        ];
    }
}
