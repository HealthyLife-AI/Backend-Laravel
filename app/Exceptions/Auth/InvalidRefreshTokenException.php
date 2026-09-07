<?php

namespace App\Exceptions\Auth;

use Exception;

/**
 * Thrown when a refresh token is missing, malformed, expired, or already
 * revoked (including reuse of a rotated-out token — see
 * RefreshTokenService::rotate()).
 */
class InvalidRefreshTokenException extends Exception
{
    public function __construct(string $message = 'Refresh token is invalid or has expired.')
    {
        parent::__construct($message);
    }
}
