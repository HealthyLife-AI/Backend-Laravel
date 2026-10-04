<?php

namespace App\Exceptions\Ai;

use RuntimeException;
use Throwable;

/**
 * Thrown by anything under App\Services\Ai for any failure mode — a
 * network/timeout error, a non-2xx response, missing/non-JSON content,
 * or content that parses as JSON but doesn't match what was asked for.
 * Deliberately never rendered to an HTTP response: every caller catches
 * this and falls back to a non-LLM path (see `AiDraftPlanService`)
 * rather than surfacing a provider outage as a user-facing error.
 */
class AiGenerationException extends RuntimeException
{
    /** @param  int|null  $retryAfter  Seconds the provider asked us to wait (its Retry-After header), when it sent one. */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
