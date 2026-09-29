<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiCodeException;
use App\Services\Consent\ConsentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-17: a patient's data endpoints are closed until they have accepted the
 * current privacy-policy version. Registered as `consent`; put it right
 * after `jwt` on every patient route EXCEPT the exempt ones (auth, invite
 * activation, the consent endpoints, GET /me/nutritionist, account
 * deletion, the FCM token).
 *
 * Only ever gates a client-role token: routes shared with nutritionists
 * (foods/search) pass a nutritionist straight through. It runs after
 * `jwt`, and an archived patient is refused there with `follow_up_ended`,
 * so that answer always takes precedence over `consent_required`.
 */
class EnsureConsentAccepted
{
    public function __construct(private readonly ConsentService $consent) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasRole('client')) {
            return $next($request);
        }

        $this->consent->assertConfigured();

        if ($this->consent->isConfigured() && ! $this->consent->hasAcceptedCurrent($user)) {
            throw new ApiCodeException(
                'You must accept the privacy policy to continue.',
                'consent_required',
                403,
                ['current_version' => $this->consent->currentVersion(), 'policy_url' => $this->consent->policyUrl()],
            );
        }

        return $next($request);
    }
}
