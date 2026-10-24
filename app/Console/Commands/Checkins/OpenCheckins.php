<?php

namespace App\Console\Commands\Checkins;

use App\Models\SubscriberCheckinSetting;
use App\Services\Checkins\CheckinService;
use App\Services\Scheduling\SelfScheduler;
use Illuminate\Console\Command;

/**
 * Phase 2, A4: the daily check-in job (09:00 clinic time).
 *
 * Closes pending check-ins older than the limit as expired (no alert), then,
 * for every active patient whose schedule is due, opens the check-in for that
 * due date (once: a second run is a no-op), sends ONE generic push, and moves
 * the next due date on by the interval. One patient failing never stops the rest.
 */
class OpenCheckins extends Command
{
    protected $signature = 'checkins:open';

    protected $description = 'Open due periodic check-ins, push the patient once, and expire old pending ones (Phase 2, A)';

    public function handle(CheckinService $checkins): int
    {
        // Recorded up front, like the other daily jobs: never twice for one slot.
        app(SelfScheduler::class)->markRan('checkins');

        $expired = $checkins->expireOld();
        $opened = 0;
        $failures = 0;

        SubscriberCheckinSetting::query()
            ->whereNotNull('interval_days')
            ->whereHas('subscriber', fn ($q) => $q->active())
            ->with(['subscriber.user'])
            ->orderBy('id')
            ->lazyById(200)
            ->each(function (SubscriberCheckinSetting $setting) use ($checkins, &$opened, &$failures): void {
                try {
                    if ($checkins->openIfDue($setting->subscriber, $setting) !== null) {
                        $opened++;
                    }
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->error("Check-in failed for subscriber {$setting->subscriber_id}: {$e->getMessage()}");
                }
            });

        app(SelfScheduler::class)->recordResult('checkins', ['opened' => $opened, 'expired' => $expired, 'failures' => $failures]);
        $this->info("{$opened} check-in(s) opened, {$expired} expired, {$failures} failure(s).");

        return self::SUCCESS;
    }
}
