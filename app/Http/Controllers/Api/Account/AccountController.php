<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Services\Clients\ClientDeletionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * BR-18: the patient deleting their own account from the app. Same
 * deletion as the nutritionist's (ClientDeletionService), plus a notice for
 * the nutritionist carrying only the patient's code and the date.
 *
 * Role `client` only, no id in the URL, password required, rate-limited
 * (the `account-deletion` limiter). Not behind the consent gate: a patient
 * who hasn't accepted the policy can still leave. An archived patient never
 * gets this far — every route answers them `follow_up_ended` — so they ask
 * their nutritionist or support (see the public account-deletion page).
 */
class AccountController extends Controller
{
    public function __construct(private readonly ClientDeletionService $deletion) {}

    public function destroy(DeleteAccountRequest $request): Response
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        // Deleting the user also deletes their refresh tokens (cascade), so
        // every session ends; the access token in hand stops working the
        // moment the user row is gone.
        $this->deletion->delete($subscriber, noticeForNutritionist: true);

        return response()->noContent();
    }
}
