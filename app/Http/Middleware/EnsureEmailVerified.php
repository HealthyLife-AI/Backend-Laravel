<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiCodeException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit BE-17: a nutritionist held to e-mail verification (see
 * User::needsEmailVerification and config/email_verification.php) cannot add
 * patients or use the AI endpoints until the address is verified.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->needsEmailVerification()) {
            throw new ApiCodeException(
                'Confirm your e-mail address to add patients and use the AI assistant. We sent you a link.',
                'email_unverified',
                403,
            );
        }

        return $next($request);
    }
}
