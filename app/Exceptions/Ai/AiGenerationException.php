<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * Thrown by anything under App\Services\Ai for any failure mode — a
 * network/timeout error, a non-2xx response, missing/non-JSON content,
 * or content that parses as JSON but doesn't match what was asked for.
 * Deliberately never rendered to an HTTP response: every caller catches
 * this and falls back to a non-LLM path (see `AiDraftPlanService`)
 * rather than surfacing a provider outage as a user-facing error.
 */
class AiGenerationException extends RuntimeException {}
