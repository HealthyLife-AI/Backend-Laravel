<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class InvalidGoogleTokenException extends RuntimeException
{
    public function __construct(string $message = 'Google sign-in could not be verified.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
