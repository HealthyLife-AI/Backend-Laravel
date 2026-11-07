<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\PatientNotification;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The one way a patient notification is created and pushed.
 *
 * Every notification becomes an inbox row, in the patient's language. It
 * is pushed through FCM only when a provider is configured, the patient has
 * a device, the category is switched on, and it isn't the patient's quiet
 * hours (in their own timezone). Otherwise the row says why not
 * (push_skipped) and is never pushed later: a push in quiet hours is
 * dropped, the inbox keeps it.
 *
 * With a dedupe key, a second event within DEBOUNCE_MINUTES of the last one
 * (e.g. a plan activated, then edited two minutes later) refreshes the same
 * row — unread again, newest — and is not pushed a second time.
 *
 * Texts come from lang/{ar,en}/notifications.php and are generic: an FCM
 * push passes through Google, so no health detail is ever put in one.
 */
class NotificationService
{
    public const DEBOUNCE_MINUTES = 5;

    public function __construct(private readonly FcmPushService $fcm, private readonly PushBatch $batch) {}

    /** @param  array<string, scalar>  $data  ids for the app's deep link; never health content */
    public function notify(User $user, string $category, string $type, array $data = [], ?string $dedupeKey = null): PatientNotification
    {
        $prefs = NotificationPreference::for($user);

        if ($dedupeKey !== null) {
            $recent = PatientNotification::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $dedupeKey)
                ->where('updated_at', '>=', now()->subMinutes(self::DEBOUNCE_MINUTES))
                ->latest('id')
                ->first();

            if ($recent !== null) {
                $recent->forceFill(['read_at' => null, 'updated_at' => now()])->save();

                return $recent;
            }
        }

        $notification = PatientNotification::create([
            'user_id' => $user->id,
            'category' => $category,
            'type' => $type,
            'title' => __("notifications.{$type}.title", [], $prefs->locale),
            'body' => __("notifications.{$type}.body", [], $prefs->locale),
            'data' => $data,
            'dedupe_key' => $dedupeKey,
        ]);

        $skip = $this->pushBlockedBy($user, $prefs, $category);

        if ($skip !== null) {
            $notification->forceFill(['push_skipped' => $skip])->save();

            return $notification;
        }

        // Inside a fan-out (batchPushes) the send is queued with the rest of the batch.
        if ($this->batch->collecting()) {
            $this->batch->add($notification->id);

            return $notification;
        }

        $push = fn () => $this->push($user, $notification);
        // Over HTTP the push goes after the response, so the nutritionist's
        // request never waits on FCM; a scheduled job just sends it.
        app()->runningInConsole() ? $push() : defer($push);

        return $notification;
    }

    /**
     * Runs a loop that notifies many patients: every in-app row is written (and its quiet hours,
     * preferences and dedupe decided) as usual, but the FCM sends are queued in chunks when the
     * loop ends (App\Jobs\SendPushNotifications) instead of being sent one by one inside it.
     */
    public function batchPushes(callable $work): mixed
    {
        return $this->batch->run($work);
    }

    /** Sends the push of a row written earlier (the queued job). A row with no device token any more is marked skipped. */
    public function deliver(PatientNotification $notification, ?User $user): void
    {
        if ($user === null || blank($user->fcm_token)) {
            $notification->forceFill(['push_skipped' => 'no_token'])->save();

            return;
        }

        $this->push($user, $notification);
    }

    /** Why this push must not be sent, or null if it may. */
    public function pushBlockedBy(User $user, NotificationPreference $prefs, string $category): ?string
    {
        return match (true) {
            ! $this->fcm->isConfigured() => 'not_configured',
            blank($user->fcm_token) => 'no_token',
            in_array($category, NotificationPreference::TOGGLEABLE, true) && ! $prefs->{$category} => 'category_off',
            $this->inQuietHours($prefs) => 'quiet_hours',
            default => null,
        };
    }

    public function inQuietHours(NotificationPreference $prefs, ?CarbonImmutable $at = null): bool
    {
        if (! $prefs->quiet_hours_enabled || $prefs->quiet_start === $prefs->quiet_end) {
            return false;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($prefs->effectiveTimezone())->format('H:i');

        // A range like 22:00–07:00 spans midnight.
        return $prefs->quiet_start < $prefs->quiet_end
            ? $now >= $prefs->quiet_start && $now < $prefs->quiet_end
            : $now >= $prefs->quiet_start || $now < $prefs->quiet_end;
    }

    private function push(User $user, PatientNotification $notification): void
    {
        try {
            $this->fcm->send($user->fcm_token, $notification->title, $notification->body, array_map('strval', [
                'notification_id' => $notification->id,
                'category' => $notification->category,
                'type' => $notification->type,
            ] + ($notification->data ?? [])));
            $notification->forceFill(['pushed_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
            $notification->forceFill(['push_skipped' => 'failed'])->save();
        }
    }
}
