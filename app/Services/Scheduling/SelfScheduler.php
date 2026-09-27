<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Makes the three scheduled jobs run without anyone setting up a cron.
 *
 * Each job has a "slot" — the most recent time it should have run
 * (06:00 daily, 20:00 daily, Monday 07:00) in `scheduling.timezone`. A job
 * is overdue when its last recorded run is older than that slot. Commands
 * record their own runs (`markRan`), so whether a run came from the real
 * cron or from here, the other path sees it and doesn't repeat it.
 *
 * `runOverdue()` is called after the response of any API request
 * (`RunOverdueScheduledTasks` middleware), throttled to one check a
 * minute and serialized by a lock, so a burst of requests runs each
 * overdue job once.
 *
 * Catch-up rules per job:
 * - alerts / weekly summaries: run late rather than never — both are
 *   idempotent (alerts don't duplicate an open one; a week's summary is
 *   replaced, not appended).
 * - log reminders: only within 3 hours of 20:00. A reminder about "today"
 *   pushed at 3am (because that's when the first request came in) would
 *   be wrong, so a missed evening is skipped instead.
 */
class SelfScheduler
{
    private const REMINDER_WINDOW_HOURS = 3;

    /** @var array<string, string> task key => artisan command */
    public const TASKS = [
        'alerts' => 'alerts:evaluate',
        'reminders' => 'notifications:send-log-reminders',
        'summaries' => 'ai-summaries:generate-weekly',
    ];

    public function markRan(string $task): void
    {
        Cache::forever($this->key($task), CarbonImmutable::now()->toIso8601String());
    }

    public function lastRan(string $task): ?CarbonImmutable
    {
        $value = Cache::get($this->key($task));

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /** @return list<string> task keys overdue right now */
    public function overdue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return array_values(array_filter(
            array_keys(self::TASKS),
            fn (string $task) => $this->isDue($task, $now),
        ));
    }

    public function isDue(string $task, CarbonImmutable $now): bool
    {
        $slot = $this->latestSlot($task, $now);
        $last = $this->lastRan($task);

        if ($last !== null && $last->greaterThanOrEqualTo($slot)) {
            return false;
        }

        if ($task === 'reminders') {
            return $now->lessThan($slot->addHours(self::REMINDER_WINDOW_HOURS));
        }

        return true;
    }

    /** The most recent moment (UTC) this task was scheduled for, at or before `$now`. */
    public function latestSlot(string $task, CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone(config('scheduling.timezone'));

        $slot = match ($task) {
            'alerts' => $local->setTime(6, 0),
            'reminders' => $local->setTime(20, 0),
            'summaries' => $local->startOfWeek(CarbonImmutable::MONDAY)->setTime(7, 0),
        };

        if ($slot->greaterThan($local)) {
            $slot = $task === 'summaries' ? $slot->subWeek() : $slot->subDay();
        }

        return $slot->utc();
    }

    /**
     * Runs every overdue task. Returns the keys it ran. Safe to call on
     * every request: bails after one cache read when checked within the
     * last minute, and never runs two catch-ups at once.
     *
     * @return list<string>
     */
    public function runOverdue(): array
    {
        if (! Cache::add('self-scheduler:throttle', 1, 60)) {
            return [];
        }

        $due = $this->overdue();
        if ($due === []) {
            return [];
        }

        $lock = Cache::lock('self-scheduler:running', 1800);
        if (! $lock->get()) {
            return [];
        }

        $ran = [];

        try {
            // The summaries job makes one LLM call per active client; this
            // runs after the response is sent, so it must not be cut off
            // by the request time limit or the client disconnecting.
            @set_time_limit(0);
            ignore_user_abort(true);

            // Re-check under the lock: another process may have just run it.
            foreach ($this->overdue() as $task) {
                try {
                    Artisan::call(self::TASKS[$task]);
                } catch (\Throwable $e) {
                    report($e);
                } finally {
                    // Marked even on failure, so a broken job is retried
                    // at its next slot rather than on every request.
                    $this->markRan($task);
                    $ran[] = $task;
                }
            }
        } finally {
            $lock->release();
        }

        return $ran;
    }

    private function key(string $task): string
    {
        return "self-scheduler:last-run:{$task}";
    }
}
