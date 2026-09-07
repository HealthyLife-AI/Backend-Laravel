<?php

namespace App\Exceptions\Auth;

use Exception;

/**
 * Thrown when an invite token is missing, malformed, expired, or already
 * used (FR-03 / BR-3: single-use invite link).
 */
class InvalidInviteTokenException extends Exception
{
    public function __construct(string $message = 'This invite link is invalid or has expired.')
    {
        parent::__construct($message);
    }
}
