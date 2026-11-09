<?php

namespace App\Jobs;

use App\Models\PatientNotification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends the FCM push of a chunk of already-written notification rows.
 *
 * Safe to run twice (a retry, or a worker that was stopped half way): a row that was pushed, or
 * that was skipped for any reason, is left alone. If the chunk runs past its time budget, the
 * rows not yet handled are queued again as a new job, so one job never outlives the worker's
 * 60 s limit however slow FCM is.
 */
class SendPushNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    /** @param  list<int>  $notificationIds */
    public function __construct(public array $notificationIds) {}

    public function handle(NotificationService $notifications): void
    {
        $deadline = now()->addSeconds((int) config('push.job_budget_seconds'));

        $rows = PatientNotification::query()
            ->whereIn('id', $this->notificationIds)
            ->whereNull('pushed_at')
            ->whereNull('push_skipped')
            ->orderBy('id')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->keyBy('id');

        foreach ($rows->values() as $position => $row) {
            // At least one send per run, so a run always makes progress.
            if ($position > 0 && now()->greaterThanOrEqualTo($deadline)) {
                self::dispatch($rows->slice($position)->pluck('id')->values()->all());

                return;
            }

            $notifications->deliver($row, $users->get($row->user_id));
        }
    }
}
