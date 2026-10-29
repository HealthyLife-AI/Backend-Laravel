<?php

namespace App\Exceptions\Backup;

use RuntimeException;

/**
 * A backup step failed. The message is written by this code only (a stage and a
 * reason), never taken from a driver, a client or the database: those can carry a
 * host, a bot token in a URL, or a row.
 */
class BackupException extends RuntimeException
{
    public function __construct(public readonly string $stage, string $message)
    {
        parent::__construct($message);
    }
}
