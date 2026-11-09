<?php

namespace App\Services\Notifications;

use App\Jobs\SendPushNotifications;
use Throwable;

/**
 * Collects the notifications that should be pushed while a fan-out runs, then queues their FCM
 * sends as chunked jobs once the fan-out is done.
 *
 * A singleton, so every NotificationService in the process (a command's own, and the one inside
 * CheckinService or PostService) feeds the same batch. Inside run(), NotificationService::notify()
 * still writes the in-app row, checks quiet hours, preferences and the dedupe key exactly as
 * before; the only difference is that an allowed push is remembered here instead of being sent.
 */
class PushBatch
{
    private int $depth = 0;

    /** @var list<int> */
    private array $ids = [];

    public function collecting(): bool
    {
        return $this->depth > 0;
    }

    public function add(int $notificationId): void
    {
        $this->ids[] = $notificationId;
    }

    /**
     * Runs $work with push sends collected; when the outermost call ends (even by an exception),
     * what was collected is queued.
     */
    public function run(callable $work): mixed
    {
        $this->depth++;

        try {
            return $work();
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $this->flush();
            }
        }
    }

    private function flush(): void
    {
        $ids = $this->ids;
        $this->ids = [];

        foreach (array_chunk($ids, max(1, (int) config('push.chunk_size'))) as $chunk) {
            try {
                SendPushNotifications::dispatch($chunk);
            } catch (Throwable $e) {
                // The queue is down: the in-app rows exist, the pushes are lost. Never fail the caller for it.
                report($e);
            }
        }
    }
}
