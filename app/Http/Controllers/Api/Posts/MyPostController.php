<?php

namespace App\Http\Controllers\Api\Posts;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientPostResource;
use App\Models\NutritionistPost;
use App\Models\Subscriber;
use App\Services\Posts\PostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 3, A5: the patient's "نصائح أخصائيتك". No route-bound subscriber (it
 * comes from the token), and a post the patient is not in the audience of is a
 * 404, never a 403 that would confirm it exists.
 */
class MyPostController extends Controller
{
    public function __construct(private readonly PostService $posts) {}

    /** Paginated, pinned first then newest. */
    public function index(): AnonymousResourceCollection
    {
        return PatientPostResource::collection($this->feed($this->subscriber())->forFeed()->paginate(20));
    }

    public function show(int $id): PatientPostResource
    {
        return new PatientPostResource($this->feed($this->subscriber())->findOrFail($id));
    }

    /** Idempotent: the first read time is kept. */
    public function read(int $id): JsonResponse
    {
        $subscriber = $this->subscriber();
        $post = NutritionistPost::query()->visibleTo($subscriber)->findOrFail($id);

        $this->posts->markRead($post, $subscriber);

        return response()->json(['id' => $post->id, 'is_read' => true]);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['unread' => $this->posts->unreadCount($this->subscriber())]);
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }

    /**
     * Visible posts, each with whether this patient has opened it.
     *
     * @return Builder<NutritionistPost>
     */
    private function feed(Subscriber $subscriber): Builder
    {
        return NutritionistPost::query()->visibleTo($subscriber)
            ->select('nutritionist_posts.*')
            ->selectRaw('exists (select 1 from nutritionist_post_reads r where r.post_id = nutritionist_posts.id and r.subscriber_id = ?) as is_read', [$subscriber->id]);
    }
}
