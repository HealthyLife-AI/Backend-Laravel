<?php

namespace App\Services\Posts;

use App\Models\NutritionistPost;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3, A: the life of an education post.
 *
 * draft -> publish (the FIRST time stamps `published_at` and pushes ONE generic
 * "new tip" to each audience member; unpublish and publish again, or editing a
 * published post, never push again) -> unpublish hides it from the patients
 * without deleting it or its reads.
 */
class PostService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @param  array{title: string, body: string, audience: string, condition_codes?: list<string>|null, patient_ids?: list<int>|null, pinned?: bool}  $data
     */
    public function create(User $author, array $data): NutritionistPost
    {
        return DB::transaction(function () use ($author, $data): NutritionistPost {
            $post = NutritionistPost::create($this->attributes($data) + ['author_id' => $author->id, 'status' => NutritionistPost::STATUS_DRAFT]);
            $this->syncTargets($post, $data);

            return $post;
        });
    }

    /** @param  array<string, mixed>  $data  only the keys present are changed */
    public function update(NutritionistPost $post, array $data): NutritionistPost
    {
        return DB::transaction(function () use ($post, $data): NutritionistPost {
            // What is not sent keeps its current value, so the audience rules are applied to the whole picture.
            $merged = $data + ['audience' => $post->audience, 'condition_codes' => $post->condition_codes];

            $post->fill($this->attributes($merged))->save();
            $this->syncTargets($post, $merged);

            return $post;
        });
    }

    public function publish(NutritionistPost $post): NutritionistPost
    {
        $first = $post->published_at === null;

        $post->forceFill([
            'status' => NutritionistPost::STATUS_PUBLISHED,
            'published_at' => $post->published_at ?? now(),
        ])->save();

        if ($first) {
            $this->pushToAudience($post);
        }

        return $post;
    }

    public function unpublish(NutritionistPost $post): NutritionistPost
    {
        $post->forceFill(['status' => NutritionistPost::STATUS_DRAFT])->save();

        return $post;
    }

    /** @return array{audience_count: int, read_count: int} */
    public function stats(NutritionistPost $post): array
    {
        $audience = $post->audienceSubscribers();

        return [
            'audience_count' => (clone $audience)->count(),
            // Only readers who are (still) in the audience.
            'read_count' => $post->reads()->whereIn('subscriber_id', (clone $audience)->select('id'))->count(),
        ];
    }

    /** The patient opened it. Idempotent: the first read time is kept. */
    public function markRead(NutritionistPost $post, Subscriber $subscriber): void
    {
        DB::table('nutritionist_post_reads')->insertOrIgnore([
            'post_id' => $post->id,
            'subscriber_id' => $subscriber->id,
            'read_at' => now(),
        ]);
    }

    /** How many visible posts this patient has not opened. */
    public function unreadCount(Subscriber $subscriber): int
    {
        return NutritionistPost::query()->visibleTo($subscriber)
            ->whereNotIn('id', DB::table('nutritionist_post_reads')->select('post_id')->where('subscriber_id', $subscriber->id))
            ->count();
    }

    private function pushToAudience(NutritionistPost $post): void
    {
        $post->audienceSubscribers()->with('user')->orderBy('id')->lazyById(200)->each(function (Subscriber $subscriber) use ($post): void {
            if ($subscriber->user === null) {
                return;
            }

            try {
                $this->notifications->notify($subscriber->user, 'education', 'education_post', ['post_id' => $post->id], "post:{$post->id}");
            } catch (\Throwable $e) {
                // One failing push must not stop the rest, nor undo the publish.
                report($e);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['title', 'body', 'audience', 'pinned']));

        if (isset($attributes['title'])) {
            $attributes['title'] = trim($attributes['title']);
        }

        if (isset($attributes['body'])) {
            $attributes['body'] = trim($attributes['body']);
        }

        // Codes only mean something for the `conditions` audience.
        $audience = $data['audience'] ?? null;
        if ($audience !== null) {
            $attributes['condition_codes'] = $audience === 'conditions' ? array_values(array_unique((array) ($data['condition_codes'] ?? []))) : null;
        }

        return $attributes;
    }

    /** @param  array<string, mixed>  $data */
    private function syncTargets(NutritionistPost $post, array $data): void
    {
        if (($data['audience'] ?? null) !== 'patients') {
            $post->targets()->detach();

            return;
        }

        if (array_key_exists('patient_ids', $data)) {
            $post->targets()->sync(array_values(array_unique((array) $data['patient_ids'])));
        }
    }
}
