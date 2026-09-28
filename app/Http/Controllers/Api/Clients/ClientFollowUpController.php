<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Models\Subscriber;
use App\Services\Clients\FollowUpService;
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

    /**
     * A patient who never activated gets a fresh invite (their old one was
     * invalidated on archive), returned the same way as on creation.
     */
    public function resume(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $invite = $this->followUp->resume($subscriber);

        return response()->json([
            'client' => new SubscriberResource($subscriber->refresh()->load('user')),
            'invite_token' => $invite['plain'] ?? null,
            'invite_expires_at' => isset($invite) ? $invite['model']->expires_at->toIso8601String() : null,
        ]);
    }
}
