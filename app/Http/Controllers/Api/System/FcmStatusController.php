<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Notifications\FcmPushService;
use Illuminate\Http\JsonResponse;

/**
 * The FCM twin of `AiStatusController` — same reason to exist. Setting a
 * Firebase env var in a hosting dashboard does not prove the running
 * container received it *unmangled*, and `notifications:send-log-reminders`
 * fails closed by design (S5-06): no push, no error, so a silently
 * misconfigured environment looks identical to a correctly configured one
 * with nothing due to send yet. This is the one way to tell them apart
 * from outside the container, without SSH/console access and without
 * sending a real push to find out.
 *
 * `source` matters beyond "is it configured": this project's own Taqat
 * deployment broke login/register outright when `credentials_json` held
 * the raw JSON — its `"` characters corrupted Taqat's own env-var
 * storage before the app ever got a chance to log an exception. A
 * response reading `source: "credentials_json"` on a PaaS deploy is
 * itself worth a second look, not just confirmation that "configured" is
 * true — see `config/firebase.php`.
 *
 * Reports which source is active — never the JSON/key content itself,
 * and no live call to Firebase (which would let any authenticated user
 * trigger a real token exchange by polling).
 */
class FcmStatusController extends Controller
{
    public function __construct(private readonly FcmPushService $fcm) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'configured' => $this->fcm->isConfigured(),
            'source' => match (true) {
                filled(config('firebase.credentials_json_base64')) => 'credentials_json_base64',
                filled(config('firebase.credentials_json')) => 'credentials_json',
                filled(config('firebase.credentials_path')) => 'credentials_path',
                default => null,
            },
        ]);
    }
}
