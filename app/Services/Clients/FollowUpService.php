<?php

namespace App\Services\Clients;

use App\Models\ClientInvite;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\Appointments\AppointmentService;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Support\Facades\DB;

/**
 * End and resume a patient's follow-up ("إنهاء المتابعة" / "استئناف المتابعة").
 *
 * Archiving keeps every record. What stops is everything that acts on the
 * patient: Subscriber::active() drops them from the scheduled jobs and the
 * active counts, EnsureFollowUpActive refuses new plans, edits and
 * measurements, and JwtAuthenticate / AuthController refuse the patient's
 * own app with `follow_up_ended`.
 */
class FollowUpService
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly ClientInviteService $invites,
        private readonly AdherenceService $adherence,
    ) {}

    public function archive(Subscriber $subscriber): void
    {
        if ($subscriber->isArchived()) {
            return;
        }

        DB::transaction(function () use ($subscriber) {
            $subscriber->forceFill(['archived_at' => now()])->save();

            // Future appointments are cancelled and the patient told once.
            app(AppointmentService::class)->cancelFutureFor($subscriber);

            // Ends every session: the app can't refresh its access token.
            $this->refreshTokens->revokeAllForUser($subscriber->user);

            // A not-yet-activated patient can't accept an old invite link.
            $subscriber->invites()
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);
        });
    }

    /**
     * Returns a new invite when the patient never activated (their old
     * one was invalidated on archive), otherwise null.
     *
     * @return array{plain: string, model: ClientInvite}|null
     */
    /**
     * What the app's "follow-up ended" screen shows, sent in the 403 body:
     * how to reach the nutritionist, how many whole weeks the follow-up ran
     * (at least 1), and the adherence rate over that whole period (null if
     * nothing was logged).
     *
     * @return array{nutritionist: array{name: string|null, whatsapp_number: string|null}, weeks_followed: int, adherence_percent: float|null}
     */
    public function endedDetails(User $user): array
    {
        $subscriber = Subscriber::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();
        $nutritionist = User::with('nutritionistProfile')->find($subscriber->nutritionist_id);
        $end = $subscriber->archived_at ?? now();

        return [
            'nutritionist' => [
                'name' => $nutritionist?->name,
                'whatsapp_number' => $nutritionist?->nutritionistProfile?->whatsapp_number,
            ],
            'weeks_followed' => max(1, intdiv((int) $subscriber->created_at->diffInDays($end), 7)),
            'adherence_percent' => $this->adherence->summary(
                $subscriber,
                $subscriber->created_at->toDateString(),
                $end->toDateString(),
            )['adherence_percent'],
        ];
    }

    public function resume(Subscriber $subscriber): ?array
    {
        if (! $subscriber->isArchived()) {
            return null;
        }

        return DB::transaction(function () use ($subscriber) {
            $subscriber->forceFill(['archived_at' => null])->save();

            // The stored status is from before the archive; bring it up to
            // date now rather than leaving it stale until the next 06:00 run.
            if ($subscriber->status === 'active') {
                $this->adherence->refreshStatus($subscriber);
            }

            return $subscriber->status === 'pending' ? $this->invites->issue($subscriber) : null;
        });
    }
}
