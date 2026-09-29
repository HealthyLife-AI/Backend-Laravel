<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refusal the patient app has to tell apart from other errors of the same
 * HTTP status, so the body carries a stable machine-readable `code`
 * alongside the message (`log_locked`, `entry_too_old`, …). Rendered in
 * bootstrap/app.php; `$extra` is merged into the body at the top level and
 * `$errors` (field => messages) is added when the refusal is about a field,
 * so a 422 keeps the standard Laravel validation shape too.
 */
class ApiCodeException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $extra = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
