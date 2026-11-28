<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\ApiCodeException;
use App\Http\Controllers\Controller;
use App\Services\Auth\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Audit BE-17: confirming a nutritionist's e-mail address. */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerification $verification) {}

    /** Public: the link's own token is the credential. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'expires' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:128'],
        ]);

        if (! $this->verification->verify((int) $data['id'], (int) $data['expires'], $data['token'])) {
            throw new ApiCodeException('This confirmation link is invalid or has expired. Ask for a new one.', 'verification_link_invalid', 422);
        }

        return response()->json(['message' => 'Your e-mail is confirmed.', 'email_verified' => true]);
    }

    /** A new link for the signed-in nutritionist. A verified one gets the same answer and no mail. */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at === null) {
            $this->verification->send($user, $request->input('locale') === 'en' ? 'en' : 'ar');
        }

        return response()->json(['message' => 'If your e-mail is not confirmed yet, a new link has been sent.']);
    }
}
