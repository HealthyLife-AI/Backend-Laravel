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

    /*
    |--------------------------------------------------------------------------
    | Weekly Summary — separate credential (S5-03/S5-04, optional)
    |--------------------------------------------------------------------------
    |
    | AiDraftPlanService (above) and WeeklySummaryService share ONE Groq
    | free-tier key by default — fine at low volume, but the two features
    | now compete for the same rate limit (8000 TPM / 1000 RPD on Groq's
    | free tier — see the AI-status work earlier this project), and a
    | draft request failing over to rule-based because the WEEKLY JOB used
    | up the quota is a confusing failure to debug.
    |
    | Every OPENAI_SUMMARY_* var falls back to the corresponding OPENAI_*
    | one when unset, so this is purely additive: leave these four blank
    | and nothing changes — both features keep sharing one key exactly as
    | before. Set them (a second free Groq account works fine, or any
    | other OpenAI-compatible provider) to split the two onto independent
    | quotas. `OpenAiCompatibleClient` doesn't know or care which "ai.*"
    | key it was built from — see AppServiceProvider's contextual binding
    | for how WeeklySummaryService gets this profile instead of the
    | default one.
    |
    */

    'summary' => [
        'base_url' => env('OPENAI_SUMMARY_BASE_URL', env('OPENAI_BASE_URL')),
        'api_key' => env('OPENAI_SUMMARY_API_KEY', env('OPENAI_API_KEY')),
        'model' => env('OPENAI_SUMMARY_MODEL', env('OPENAI_MODEL')),
        'timeout' => (int) env('OPENAI_SUMMARY_TIMEOUT', env('OPENAI_TIMEOUT', 12)),
    ],

];
