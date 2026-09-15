<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Notifications\FcmPushService;
use Illuminate\Http\JsonResponse;

/**
 * The FCM twin of `AiStatusController` — same reason to exist. Setting
 * `FIREBASE_CREDENTIALS_JSON` in a hosting dashboard does not prove the
 * running container ever received it, and `notifications:send-log-reminders`
 * fails closed by design (S5-06): no push, no error, so a silently
 * misconfigured environment looks identical to a correctly configured one
 * with nothing due to send yet. This is the one way to tell them apart
 * from outside the container, without SSH/console access and without
 * sending a real push to find out.
 *
 * Reports which of the two sources is active — never the JSON/key
 * content itself, and no live call to Firebase (which would let any
 * authenticated user trigger a real token exchange by polling).
 */
class FcmStatusController extends Controller
{
    public function __construct(private readonly FcmPushService $fcm) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'configured' => $this->fcm->isConfigured(),
            'source' => match (true) {
                filled(config('firebase.credentials_json')) => 'credentials_json',
                filled(config('firebase.credentials_path')) => 'credentials_path',
                default => null,
            },
        ]);
    }
}
