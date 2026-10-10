<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Models\Subscriber;
use App\Services\Clients\FollowUpService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;

/**
 * End follow-up (archive) and resume it. Both are idempotent. Another
 * nutritionist's patient is a 404, like every other client route.
 */
class ClientFollowUpController extends Controller
{
    public function __construct(private readonly FollowUpService $followUp) {}

    public function archive(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $this->followUp->archive($subscriber);

        return response()->json(['client' => new SubscriberResource($subscriber->refresh()->load('user'))]);
    }

    public function resume(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $wasArchived = $subscriber->isArchived();
        $this->followUp->resume($subscriber);

        // Only a real resume of a patient who uses the app (a pending one hasn't signed in yet).
        if ($wasArchived && $subscriber->status === 'active' && ($user = $subscriber->user()->first()) !== null) {
            app(NotificationService::class)->notify($user, 'system', 'follow_up_resumed');
        }

        return response()->json(['client' => new SubscriberResource($subscriber->refresh()->load('user'))]);
    }
}
