<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Rules\PatientUsername;
use App\Services\Clients\PatientCredentialsService;
use App\Support\Username;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /clients/{id}/reset-password — replaces the sign-in link. The
 * nutritionist gets a new generated password (and sets or changes the
 * username) to send on WhatsApp; every session of the patient ends.
 * A username is required for patients added before usernames existed.
 */
class ClientPasswordResetController extends Controller
{
    public function __construct(private readonly PatientCredentialsService $credentials) {}

    public function store(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $user = $subscriber->user;

        if (is_string($request->input('username'))) {
            $request->merge(['username' => Username::normalize($request->input('username'))]);
        }

        $data = $request->validate([
            'username' => [
                'nullable',
                'string',
                new PatientUsername,
                Rule::unique('users', 'username')->ignore($user->id),
            ],
        ], ['username.unique' => 'This username is taken.']);

        // A patient with no username (added before usernames) gets one generated when none is given.
        $username = $data['username'] ?? ($user->username === null ? Username::generate((string) $subscriber->code) : null);

        return response()->json($this->credentials->reset($subscriber, $username));
    }
}
