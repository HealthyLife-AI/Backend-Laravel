<?php

namespace App\Services\Checkins;

use App\Exceptions\ApiCodeException;
use App\Models\Alert;
use App\Models\PatientCheckin;
use App\Models\Subscriber;
use App\Models\SubscriberCheckinSetting;
use App\Models\User;
use App\Services\Alerts\AlertSeverity;
use App\Services\Notifications\NotificationService;
use App\Support\ClinicDay;

/**
 * Phase 2, A: the periodic check-in lifecycle.
 *
 * settings -> (daily job, or at once when set for today) a PENDING check-in
 * for the due date + one generic push -> the patient submits -> a flagged
 * answer opens a `checkin_flag` alert linked to the check-in -> the
 * nutritionist marks it reviewed, which resolves exactly that alert. An
 * unanswered one is closed as `expired` after `checkin.expire_after_days`,
 * with no alert.
 */
class CheckinService
{
    public function __construct(
        private readonly CheckinQuestionnaire $questionnaire,
        private readonly NotificationService $notifications,
        private readonly AlertSeverity $severity,
    ) {}

    /**
     * Sets the schedule: 7 or 14 days, or null for off. `$nextDueOn` defaults to
     * today. A due date of today (or earlier) opens the first check-in right
     * away rather than waiting for the next 09:00 run.
     */
    public function configure(Subscriber $subscriber, ?int $intervalDays, ?string $nextDueOn, ?User $by): SubscriberCheckinSetting
    {
        $today = ClinicDay::today()->toDateString();

        $setting = SubscriberCheckinSetting::query()->updateOrCreate(
            ['subscriber_id' => $subscriber->id],
            [
                'interval_days' => $intervalDays,
                'next_due_on' => $intervalDays === null ? null : ($nextDueOn ?? $today),
                'updated_by' => $by?->id,
            ],
        );

        if ($intervalDays !== null) {
            $this->openIfDue($subscriber, $setting);
        }

        return $setting;
    }

    /**
     * When the setting is due: creates the check-in for that due date (once:
     * the unique key makes a second run a no-op), pushes once, and moves the
     * next due date on by the interval (past today, so a missed run never
     * piles up old check-ins).
     *
     * @return PatientCheckin|null the one just opened
     */
    public function openIfDue(Subscriber $subscriber, SubscriberCheckinSetting $setting): ?PatientCheckin
    {
        $today = ClinicDay::today();

        if ($setting->interval_days === null || $setting->next_due_on === null || $setting->next_due_on->toDateString() > $today->toDateString()) {
            return null;
        }

        $dueOn = $setting->next_due_on->toDateString();

        $checkin = PatientCheckin::query()->firstOrCreate(
            ['subscriber_id' => $subscriber->id, 'due_on' => $dueOn],
            ['status' => PatientCheckin::STATUS_PENDING],
        );

        $next = $setting->next_due_on->copy();
        do {
            $next = $next->addDays($setting->interval_days);
        } while ($next->toDateString() <= $today->toDateString());
        $setting->forceFill(['next_due_on' => $next->toDateString()])->save();

        if (! $checkin->wasRecentlyCreated) {
            return null;
        }

        // A newer questionnaire replaces any older one still waiting, so the patient never has two.
        PatientCheckin::query()->where('subscriber_id', $subscriber->id)->where('status', PatientCheckin::STATUS_PENDING)
            ->where('id', '!=', $checkin->id)->update(['status' => PatientCheckin::STATUS_EXPIRED]);

        $user = $subscriber->user;

        if ($user !== null) {
            try {
                $this->notifications->notify($user, 'nutritionist', 'checkin_due', ['checkin_id' => $checkin->id], "checkin:{$subscriber->id}:{$dueOn}");
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $checkin;
    }

    /** Pending check-ins older than the limit become expired. No alert. */
    public function expireOld(): int
    {
        $cutoff = ClinicDay::today()->subDays((int) config('checkin.expire_after_days'))->toDateString();

        return PatientCheckin::query()->where('status', PatientCheckin::STATUS_PENDING)->where('due_on', '<', $cutoff)
            ->update(['status' => PatientCheckin::STATUS_EXPIRED]);
    }

    /** The check-in the patient can answer now: their newest pending one. */
    public function current(Subscriber $subscriber): ?PatientCheckin
    {
        return PatientCheckin::query()->where('subscriber_id', $subscriber->id)->where('status', PatientCheckin::STATUS_PENDING)
            ->orderByDesc('due_on')->first();
    }

    /** @param  array<string, mixed>  $answers  raw, validated here */
    public function submit(PatientCheckin $checkin, array $answers): PatientCheckin
    {
        if ($checkin->status === PatientCheckin::STATUS_SUBMITTED) {
            throw new ApiCodeException('This check-in was already answered.', 'checkin_already_submitted', 409);
        }

        if ($checkin->status === PatientCheckin::STATUS_EXPIRED) {
            throw new ApiCodeException('This check-in has expired.', 'checkin_expired', 409);
        }

        $clean = $this->questionnaire->validate($answers);
        $flagged = $this->questionnaire->flags($clean);

        $checkin->forceFill([
            'status' => PatientCheckin::STATUS_SUBMITTED,
            'answers' => $clean,
            'questionnaire_version' => $this->questionnaire->version(),
            'submitted_at' => now(),
            'flagged' => $flagged,
        ])->save();

        if ($flagged) {
            $subscriber = Subscriber::withoutGlobalScopes()->findOrFail($checkin->subscriber_id);

            $subscriber->alerts()->create([
                'checkin_id' => $checkin->id,
                'type' => Alert::TYPE_CHECKIN_FLAG,
                'severity' => $this->severity->for($subscriber, Alert::TYPE_CHECKIN_FLAG),
                // A fixed sentence, like every alert message: the answers stay on the check-in.
                'message' => 'A check-in needs attention.',
            ]);
        }

        return $checkin;
    }

    /** The nutritionist has read it: resolves exactly its own alert. */
    public function review(PatientCheckin $checkin, User $by): PatientCheckin
    {
        if ($checkin->reviewed_at === null) {
            $checkin->forceFill(['reviewed_at' => now(), 'reviewed_by' => $by->id])->save();
        }

        Alert::query()->where('checkin_id', $checkin->id)->open()->update(['resolved_at' => now()]);

        return $checkin;
    }
}
