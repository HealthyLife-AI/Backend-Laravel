<?php

namespace App\Services\Consent;

use App\Exceptions\ApiCodeException;
use App\Models\PatientConsent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * BR-17: patient consent to the privacy policy.
 *
 * Always enforced: config('patient_app.consent.version') always has a value
 * (CONSENT_VERSION, or the default in config/patient_app.php), and a patient
 * who hasn't accepted THAT version is refused (403 `consent_required`). A
 * missing variable therefore never turns the gate off and never locks the
 * app; the admin overview warns about it instead (`configuration()`).
 */
class ConsentService
{
    public function currentVersion(): string
    {
        return (string) config('patient_app.consent.version');
    }

    public function policyUrl(): ?string
    {
        return config('patient_app.consent.policy_url');
    }

    /**
     * For the admin overview: the values in force and whether each was set
     * explicitly in the environment (a default is in use otherwise).
     *
     * @return array{version: string, version_set: bool, policy_url: string|null, policy_url_set: bool}
     */
    public function configuration(): array
    {
        return [
            'version' => $this->currentVersion(),
            'version_set' => (bool) config('patient_app.consent.version_from_env'),
            'policy_url' => $this->policyUrl(),
            'policy_url_set' => (bool) config('patient_app.consent.policy_url_from_env'),
        ];
    }

    public function hasAcceptedCurrent(User $user): bool
    {
        return PatientConsent::query()->where('user_id', $user->id)->where('version', $this->currentVersion())->exists();
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
            'required' => ! $this->hasAcceptedCurrent($user),
            'current_version' => $this->currentVersion(),
            'accepted_version' => $latest?->version,
            'accepted_at' => $latest?->accepted_at?->toIso8601String(),
            'policy_url' => $this->policyUrl(),
        ];
    }

    /**
     * What the nutritionist sees on the patient's page: the version the
     * patient last accepted and when, and whether that is the current one.
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
            'up_to_date' => $this->hasAcceptedCurrent($user),
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
        $current = $this->currentVersion();

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
