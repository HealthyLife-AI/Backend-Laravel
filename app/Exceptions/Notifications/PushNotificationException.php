<?php

namespace App\Exceptions\Notifications;

use RuntimeException;

/**
 * Mirrors AiGenerationException's role for the LLM client: internal only,
 * never rendered to an HTTP response, always caught by the caller and
 * turned into a log line plus a skipped send — never a failed request or
 * a halted batch job.
 */
class PushNotificationException extends RuntimeException {}
