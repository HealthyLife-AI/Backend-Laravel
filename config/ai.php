<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI-Compatible Chat Completion (F-5 / S3-06 optional upgrade)
    |--------------------------------------------------------------------------
    |
    | Powers `AiDraftPlanService`'s LLM path — currently Groq's free tier
    | (an OpenAI-compatible endpoint), chosen over standing up a paid
    | provider for a P1 feature the PRD itself flags as unconfirmed-wanted.
    | `base_url` deliberately has no default pointing at api.openai.com:
    | this app was never built against real OpenAI, and defaulting to it
    | would silently try (and fail) against a provider nobody configured.
    |
    | `api_key` empty (the default with nothing in .env) is how the whole
    | LLM path turns itself off: `AiDraftPlanService` checks this before
    | ever attempting a call and falls back to the rule-based generator
    | that shipped in S3-06 — same behavior as before this file existed,
    | not a degraded mode.
    |
    */

    'base_url' => env('OPENAI_BASE_URL'),

    'api_key' => env('OPENAI_API_KEY'),

    'model' => env('OPENAI_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | A meal-plan draft is requested synchronously from an HTTP request
    | (no queue) — this must stay well under PHP's own request timeout,
    | and short enough that a slow/hung provider fails over to the
    | rule-based generator instead of hanging the nutritionist's browser.
    |
    */

    'timeout' => (int) env('OPENAI_TIMEOUT', 12),

];
