<?php

namespace App\Services\FollowUp;

use App\Exceptions\ApiCodeException;
use App\Models\FollowUpReview;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up between sessions: the nutritionist's reviews (rating, note, key
 * points, tasks) and the patient's notifications about them.
 *
 * A new review notifies the patient once. Editing one the patient already
 * acknowledged («فهمت») clears the acknowledgement and notifies once more
 * (debounced per review). Editing tasks keeps each existing task's done
 * state; tasks left out of an edit are removed.
 */
class ReviewService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @param  array<string, mixed>  $data  validated StoreReviewRequest */
    public function create(Subscriber $subscriber, User $nutritionist, array $data): FollowUpReview
    {
        $this->assertNotEmpty($data);

        $review = DB::transaction(function () use ($subscriber, $nutritionist, $data) {
            $review = FollowUpReview::create([
                'subscriber_id' => $subscriber->id,
                'nutritionist_id' => $nutritionist->id,
                'rating' => $data['rating'] ?? null,
                'note' => $data['note'] ?? null,
                'key_points' => $this->cleanPoints($data['key_points'] ?? []),
            ]);
            $this->syncTasks($review, $data['tasks'] ?? []);

            return $review;
        });

        $this->notify($subscriber, $review, 'review_new');

        return $review->load('tasks');
    }

    /** @param  array<string, mixed>  $data */
    public function update(FollowUpReview $review, array $data): FollowUpReview
    {
        $this->assertNotEmpty($data);
        $wasAcknowledged = $review->acknowledged_at !== null;

        $changed = DB::transaction(function () use ($review, $data) {
            $review->fill([
                'rating' => $data['rating'] ?? null,
                'note' => $data['note'] ?? null,
                'key_points' => $this->cleanPoints($data['key_points'] ?? []),
            ]);
            $changed = $review->isDirty();
            $changed = $this->syncTasks($review, $data['tasks'] ?? []) || $changed;

            if ($changed) {
                $review->edited_at = now();
                $review->acknowledged_at = null;
            }
            $review->save();

            return $changed;
        });

        if ($changed && $wasAcknowledged) {
            $this->notify($review->subscriber, $review, 'review_updated');
        }

        return $review->load('tasks');
    }

    private function notify(Subscriber $subscriber, FollowUpReview $review, string $type): void
    {
        $user = $subscriber->user()->first();

        if ($user !== null) {
            $this->notifications->notify($user, 'nutritionist', $type, ['review_id' => $review->id], "review:{$review->id}");
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertNotEmpty(array $data): void
    {
        if (blank($data['rating'] ?? null) && blank($data['note'] ?? null) && $this->cleanPoints($data['key_points'] ?? []) === [] && empty($data['tasks'])) {
            throw new ApiCodeException(
                'A review needs at least a rating, a note, a key point or a task.',
                'review_empty',
                422,
            );
        }
    }

    /** @return list<string> */
    private function cleanPoints(array $points): array
    {
        return array_values(array_filter(array_map(fn ($p) => trim((string) $p), $points), fn ($p) => $p !== ''));
    }

    /**
     * Existing tasks (by id) keep their done state; new ones are added; ones
     * left out are removed. Returns whether anything changed.
     *
     * @param  list<array{id?: int|null, title: string}>  $tasks
     */
    private function syncTasks(FollowUpReview $review, array $tasks): bool
    {
        $existing = $review->tasks()->get()->keyBy('id');
        $kept = [];
        $changed = false;

        foreach (array_values($tasks) as $order => $task) {
            $current = isset($task['id']) ? $existing->get((int) $task['id']) : null;

            if ($current !== null) {
                $current->fill(['title' => trim($task['title']), 'sort_order' => $order]);
                $changed = $changed || $current->isDirty('title');
                $current->save();
                $kept[] = $current->id;

                continue;
            }

            $kept[] = $review->tasks()->create([
                'subscriber_id' => $review->subscriber_id,
                'title' => trim($task['title']),
                'sort_order' => $order,
            ])->id;
            $changed = true;
        }

        $removed = $review->tasks()->whereNotIn('id', $kept ?: [0])->delete();

        return $changed || $removed > 0;
    }
}
