<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Clients\ClientInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * A patient who can't sign in (forgotten password) has no e-mail or SMS
 * path: their nutritionist issues a new one-time sign-in link here and
 * sends it over WhatsApp. It is an invite token — same table, expiry and
 * activation endpoint — so the patient sets a new password through the
 * same flow, and activation ends every earlier session. Earlier unused
 * links for the patient stop working, so only the newest one is live.
 */
class ClientSignInLinkController extends Controller
{
    public function __construct(private readonly ClientInviteService $invites) {}

    public function store(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $invite = DB::transaction(function () use ($subscriber) {
            $subscriber->invites()
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()->subSecond()]);

            return $this->invites->issue($subscriber);
        });

        return response()->json([
            'token' => $invite['plain'],
            'expires_at' => $invite['model']->expires_at->toIso8601String(),
        ], 201);
    }
}
