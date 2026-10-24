<?php

namespace App\Services\QuickRequests;

use App\Exceptions\ApiCodeException;
use App\Models\Alert;
use App\Models\PatientQuickRequest;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertSeverity;
use App\Services\Notifications\NotificationService;
use App\Support\ClinicDay;

/**
 * Phase 1, E: patient quick requests (structured, not chat).
 *
 * Sending one opens a `quick_request` alert for the nutritionist (severity
 * by the shared rule, see AlertSeverity) and a generic push; answering or
 * closing it resolves the alert. Push texts carry no reason, note or any
 * other health detail: they are the generic lines in lang/*\/notifications.php,
 * and the data is ids only.
 *
 * Nothing here ever changes a plan.
 */
class QuickRequestService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AlertSeverity $severity,
    ) {}

    /** @param  array<string, mixed>  $data  validated request */
    public function create(Subscriber $subscriber, array $data): PatientQuickRequest
    {
        $today = ClinicDay::today()->toDateString();

        $sentToday = PatientQuickRequest::query()
            ->where('subscriber_id', $subscriber->id)
            ->where('created_at', '>=', ClinicDay::startUtc($today))
            ->where('created_at', '<=', ClinicDay::endUtc($today))
            ->count();

        if ($sentToday >= PatientQuickRequest::DAILY_LIMIT) {
            throw new ApiCodeException('You have reached the daily limit for requests. Try again tomorrow.', 'quick_request_limit', 429);
        }

        $request = PatientQuickRequest::create([
            'subscriber_id' => $subscriber->id,
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
            'occasion_date' => $data['reason'] === 'special_occasion' ? ($data['occasion_date'] ?? null) : null,
            'status' => PatientQuickRequest::STATUS_OPEN,
        ]);

        $subscriber->alerts()->create([
            'type' => Alert::TYPE_QUICK_REQUEST,
            'severity' => $this->severity->for($subscriber, Alert::TYPE_QUICK_REQUEST, $request->reason),
            // A fixed sentence, like every alert message: the reason and the note stay on the request.
            'message' => 'New quick request from the patient.',
        ]);

        $nutritionist = $subscriber->nutritionist_id === null ? null : User::find($subscriber->nutritionist_id);

        if ($nutritionist !== null) {
            $this->notifyQuietly(fn () => $this->notifications->notify($nutritionist, 'system', 'quick_request_new', ['request_id' => $request->id], "quick-request:{$request->id}"));
        }

        return $request;
    }

    public function respond(PatientQuickRequest $request, User $by, string $response): PatientQuickRequest
    {
        if ($request->status !== PatientQuickRequest::STATUS_OPEN) {
            throw new ApiCodeException('This request is no longer open.', 'quick_request_not_open', 409);
        }

        $request->forceFill([
            'status' => PatientQuickRequest::STATUS_ANSWERED,
            'response' => $response,
            'responded_by' => $by->id,
            'responded_at' => now(),
        ])->save();

        $this->settleAlerts($request->subscriber_id);

        $patient = Subscriber::withoutGlobalScopes()->find($request->subscriber_id)?->user;

        if ($patient !== null) {
            $this->notifyQuietly(fn () => $this->notifications->notify($patient, 'nutritionist', 'quick_request_answered', ['request_id' => $request->id], "quick-request-answer:{$request->id}"));
        }

        return $request;
    }

    public function close(PatientQuickRequest $request): PatientQuickRequest
    {
        if ($request->status !== PatientQuickRequest::STATUS_CLOSED) {
            $request->forceFill(['status' => PatientQuickRequest::STATUS_CLOSED])->save();
            $this->settleAlerts($request->subscriber_id);
        }

        return $request;
    }

    /**
     * Each open request has one open alert, so after one is settled the
     * surplus alerts (oldest first) are resolved: the alert count follows
     * the number of requests still waiting.
     */
    private function settleAlerts(int $subscriberId): void
    {
        $stillOpen = PatientQuickRequest::query()->where('subscriber_id', $subscriberId)->where('status', PatientQuickRequest::STATUS_OPEN)->count();

        $alerts = Alert::query()->where('subscriber_id', $subscriberId)->ofType(Alert::TYPE_QUICK_REQUEST)->open()->orderBy('id')->get();

        foreach ($alerts->take(max(0, $alerts->count() - $stillOpen)) as $alert) {
            $alert->forceFill(['resolved_at' => now()])->save();
        }
    }

    /** A failed push must never fail the request it is about. */
    private function notifyQuietly(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
