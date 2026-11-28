<?php

namespace App\Exceptions\Ai;

/**
 * Audit BE-16: our own per-minute token budget is used up, so no request was
 * sent. Code 503, never 429: AiDraftPlanService and WeeklySummaryService wait
 * out a provider 429 (its Retry-After), and a refusal made here must fall back
 * at once instead. `retryAfter` is the seconds until the next minute window.
 */
class AiBudgetExceededException extends AiGenerationException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct('The AI token budget for this minute is used up.', 503, retryAfter: $retryAfter);
    }
}
