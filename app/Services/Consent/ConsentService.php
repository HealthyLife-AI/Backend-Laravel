<?php

namespace App\Services\Consent;

use App\Exceptions\ApiCodeException;
use App\Models\PatientConsent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * BR-17: patient consent to the privacy policy.
 *
 * The gate has three states, decided by config('patient_app.consent.version'):
 *  - a version is set: enforced — a patient who hasn't accepted THAT version
 *    is refused (403 `consent_required`);
 *  - blank in `local` / `testing`: the gate is off, so development and the
 *    test suite work without a policy;
 *  - blank anywhere else: misconfigured — refused (503
 *    `consent_not_configured`) rather than silently letting every patient
 *    through with no consent on record.
 */
class ConsentService
{
    public function currentVersion(): ?string
    {
        return config('patient_app.consent.version');
    }

    public function policyUrl(): ?string
    {
        return config('patient_app.consent.policy_url');
    }

    public function isConfigured(): bool
    {
        return $this->currentVersion() !== null;
    }

    /** Blank version outside local/testing: fail closed. */
    public function isMisconfigured(): bool
    {
        return ! $this->isConfigured() && ! app()->environment(['local', 'testing']);
    }

    /** 503 `consent_not_configured` when the deployment has no policy version set. */
    public function assertConfigured(): void
    {
        if ($this->isMisconfigured()) {
            throw new ApiCodeException(
                'Patient consent is not configured on this server.',
                'consent_not_configured',
                503,
            );
        }
    }

    public function hasAcceptedCurrent(User $user): bool
    {
        $version = $this->currentVersion();

        return $version !== null
            && PatientConsent::query()->where('user_id', $user->id)->where('version', $version)->exists();
    }

    /** The patient's most recent acceptance, of any version. */
    public function latest(User $user): ?PatientConsent
    {
        return PatientConsent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What the patient app asks for: is acceptance outstanding, which
     * version is current, what they last accepted and when.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $latest = $this->latest($user);

        return [
            'required' => $this->isConfigured() && ! $this->hasAcceptedCurrent($user),
            'current_version' => $this->currentVersion(),
            'accepted_version' => $latest?->version,
            'accepted_at' => $latest?->accepted_at?->toIso8601String(),
            'policy_url' => $this->policyUrl(),
        ];
    }

    /**
     * What the nutritionist sees on the patient's page: the version the
     * patient last accepted and when, and whether that is the current one.
     * `up_to_date` is null when no version is configured.
     *
     * @return array<string, mixed>
     */
    public function dashboardStatus(User $user): array
    {
        $latest = $this->latest($user);
        $current = $this->currentVersion();

        return [
            'accepted_version' => $latest?->version,
            'accepted_at' => $latest?->accepted_at?->toIso8601String(),
            'current_version' => $current,
            'up_to_date' => $current === null ? null : $this->hasAcceptedCurrent($user),
        ];
    }

    /**
     * Records the patient's acceptance of the current version, with the
     * IP and user agent it came from. Idempotent per version: a second
     * acceptance of the same version returns the first record untouched
     * (`wasRecentlyCreated` tells the caller which it was).
     *
     * @param  string|null  $version  What the app says it is accepting; a mismatch with the current version is refused, so a patient never accepts a policy they weren't shown.
     */
    public function accept(User $user, Request $request, ?string $version = null): PatientConsent
    {
        $this->assertConfigured();

        $current = $this->currentVersion();

        if ($current === null) {
            // Local/testing with no version: nothing to accept.
            throw new ApiCodeException('There is no policy version to accept.', 'consent_not_required', 409);
        }

        if ($version !== null && $version !== $current) {
            throw new ApiCodeException(
                'The policy has changed. Fetch the current version and ask again.',
                'consent_version_mismatch',
                409,
                ['current_version' => $current],
            );
        }

        return PatientConsent::query()->firstOrCreate(
            ['user_id' => $user->id, 'version' => $current],
            [
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            ],
        );
    }
}
