<?php

namespace App\Exceptions\Auth;

use Carbon\CarbonInterface;
use Exception;

/**
 * Thrown when a login is attempted against an account currently locked
 * out after too many consecutive failed attempts (FR-04).
 */
class AccountLockedException extends Exception
{
    public function __construct(public readonly CarbonInterface $lockedUntil)
    {
        parent::__construct('Account is locked due to too many failed login attempts.');
    }
}
