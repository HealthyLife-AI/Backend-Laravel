<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\JsonResponse;

/**
 * Answers one question that is otherwise unanswerable from outside a
 * deployed environment: is the AI provider actually wired up in the
 * container that is serving requests right now?
 *
 * `AiDraftPlanService` falls back to its rule-based generator silently and
 * by design — an unconfigured provider, an unreachable one, and a response
 * that fails validation all produce a perfectly good draft, so a caller
 * cannot tell "the LLM wrote this" from "the LLM was never asked". That is
 * the right behavior for the feature and the wrong behavior for operating
 * it: diagnosing a hosted environment meant inferring the answer from the
 * arithmetic of the returned plan, because setting an environment variable
 * in a dashboard does not prove the running process ever received it.
 *
 * Reports configuration only — never the key, not even partially, and no
 * live call to the provider (which would let any authenticated user burn
 * quota by polling). `configured: true` while drafts still come back
 * rule-based means the call or its validation is failing, not the config;
 * `configured: false` means this environment never even attempts one.
 */
class AiStatusController extends Controller
{
    public function __construct(private readonly OpenAiCompatibleClient $llm) {}

    public function __invoke(): JsonResponse
    {
        $baseUrl = (string) config('ai.base_url');

        return response()->json([
            'configured' => $this->llm->isConfigured(),
            'provider_host' => $baseUrl === '' ? null : parse_url($baseUrl, PHP_URL_HOST),
            'model' => config('ai.model'),
            'timeout_seconds' => (int) config('ai.timeout'),
        ]);
    }
}
