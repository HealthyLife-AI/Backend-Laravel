<?php

namespace App\Http\Controllers\Api\Consent;

use App\Http\Controllers\Controller;
use App\Services\Consent\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * BR-17: the patient app's consent screen — is acceptance outstanding, and
 * record it. Exempt from the consent gate itself (it is how a patient gets
 * through it); role `client` only, and the user is the token's, so there is
 * no id in the URL.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consent) {}

    public function show(Request $request): JsonResponse
    {
        if ($request->query('type') === 'medical_reports') {
            abort_unless(config('medical_reports.enabled'), 404);

            return response()->json($this->consent->medicalReportsStatus(Auth::user()));
        }

        return response()->json($this->consent->status(Auth::user()));
    }

    /**
     * 201 the first time this version is accepted, 200 for a repeat (the
     * first record stands). `version` is optional: the app may echo the
     * version it showed, and a mismatch with the current one is a 409.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['nullable', 'string', 'max:64'], 'type' => ['nullable', 'in:privacy,medical_reports']]);
        $user = Auth::user();

        // Phase 4, A4: the consent for uploading medical reports (only while the feature is on).
        if (($data['type'] ?? 'privacy') === 'medical_reports') {
            abort_unless(config('medical_reports.enabled'), 404);

            $consent = $this->consent->acceptMedicalReports($user, $request, $data['version'] ?? null);

            return response()->json($this->consent->medicalReportsStatus($user), $consent->wasRecentlyCreated ? 201 : 200);
        }

        $consent = $this->consent->accept($user, $request, $data['version'] ?? null);

        return response()->json($this->consent->status($user), $consent->wasRecentlyCreated ? 201 : 200);
    }
}
