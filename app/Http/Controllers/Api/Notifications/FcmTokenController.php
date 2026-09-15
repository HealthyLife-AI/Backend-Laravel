<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StoreFcmTokenRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * S5-06 / FR-22: the client registers (or clears) the device token their
 * own app receives push notifications on. No route-bound id — resolved
 * from the JWT, same shape as every other `me/...` endpoint.
 */
class FcmTokenController extends Controller
{
    public function store(StoreFcmTokenRequest $request): Response
    {
        Auth::user()->update(['fcm_token' => $request->input('fcm_token')]);

        return response()->noContent();
    }
}
