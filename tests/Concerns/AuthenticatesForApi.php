<?php

namespace Tests\Concerns;

use App\Models\PatientConsent;
use App\Models\User;
use App\Services\Auth\JwtService;

/**
 * The API authenticates via the custom `jwt` middleware (Bearer access
 * token), not a Laravel session guard — `actingAs()` doesn't apply here.
 */
trait AuthenticatesForApi
{
    /** @return array{Authorization: string} */
    protected function bearerFor(User $user): array
    {
        // BR-17's consent gate is always on, so a patient token would be
        // refused on every data endpoint. Tests that aren't about consent
        // get a patient who has accepted the current version; tests that
        // are override acceptsConsentForPatients() to return false.
        if ($this->acceptsConsentForPatients() && $user->hasRole('client')) {
            PatientConsent::query()->firstOrCreate(
                ['user_id' => $user->id, 'version' => config('patient_app.consent.version')],
                ['accepted_at' => now()],
            );
        }

        $token = app(JwtService::class)->issueAccessToken($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    protected function acceptsConsentForPatients(): bool
    {
        return true;
    }
}
