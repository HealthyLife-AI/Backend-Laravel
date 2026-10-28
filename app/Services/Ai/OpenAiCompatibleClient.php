<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiGenerationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin wrapper around any OpenAI-compatible `/chat/completions`
 * endpoint — currently pointed at Groq's free tier (config/ai.php), but
 * not written against Groq specifically: the same client works
 * unchanged against real OpenAI, or any other provider that speaks the
 * same REST shape, by changing three env vars and nothing else.
 *
 * Config is injected, not hardcoded to `config('ai.*')`: S5-03 gave
 * `WeeklySummaryService` its own optional credential profile
 * (`config('ai.summary')`) so it doesn't have to share Sprint 3's Groq
 * quota with `AiDraftPlanService` — two features hitting one free-tier
 * rate limit made a draft's fallback-to-rule-based depend on whether the
 * weekly job happened to run recently, which is a confusing thing to
 * debug. Omitting the constructor argument (every existing caller) keeps
 * reading `config('ai.*')` exactly as before — this is additive, not a
 * behavior change for anything that doesn't opt in.
 *
 * No SDK dependency: this is one HTTP call with a fixed shape, not
 * enough surface to justify a package over Laravel's own `Http` facade.
 */
class OpenAiCompatibleClient
{
    /**
     * Output tokens a reasoning model is given on top of the caller's answer
     * budget, because its hidden reasoning is counted against
     * `max_completion_tokens` too.
     */
    public const REASONING_HEADROOM_TOKENS = 1500;

    /** The answer budget when the caller names none. */
    public const DEFAULT_ANSWER_TOKENS = 1024;

    /** Whether the last chatJson() call had to fall back to plain text (after the provider refused its JSON mode). */
    public bool $lastCallRetried = false;

    /** @param  array{base_url?: string|null, api_key?: string|null, model?: string|null, timeout?: int}|null  $config */
    public function __construct(private readonly ?array $config = null) {}

    public function isConfigured(): bool
    {
        return filled($this->setting('base_url')) && filled($this->setting('api_key'));
    }

    public function model(): ?string
    {
        $model = $this->setting('model');

        return is_string($model) ? $model : null;
    }

    /**
     * Sends a chat completion request with `response_format: json_object`
     * (part of the OpenAI-compatible spec Groq also implements) and
     * returns the reply as a decoded object. Every failure mode (network
     * error, non-2xx, missing content, content that holds no JSON object)
     * throws `AiGenerationException` rather than returning something the
     * caller might mistake for a real result.
     *
     * Two things that used to fail every short Arabic answer are handled here,
     * once, for every feature:
     *
     * - A reasoning model (gpt-oss) spends output tokens thinking before it
     *   writes, and they count against `max_completion_tokens`. With a small
     *   limit the answer was cut off mid-object and Groq refused it:
     *   400 `json_validate_failed`, "Failed to validate JSON". The client now
     *   asks such a model for LOW reasoning effort (unless the caller chose)
     *   and adds REASONING_HEADROOM_TOKENS to the answer budget.
     * - If the provider still refuses its JSON mode with `json_validate_failed`,
     *   the request is repeated ONCE without `response_format` and the first
     *   complete {...} block of the text is used. Only if that fails too does
     *   the caller see an exception (and fall back as it always did).
     *
     * `$options['max_completion_tokens']` is the size of the ANSWER the caller
     * expects; the client sizes the request around it.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  extra request fields for this call (e.g. max_completion_tokens)
     * @param  array{feature?: string, nutritionist_id?: int|null, subscriber_id?: int|null, attempt_base?: int}  $context  who and what the call is for, for the usage log: ids and a feature name, never text
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, array $options = [], array $context = []): array
    {
        if (! $this->isConfigured()) {
            throw new AiGenerationException('No AI provider is configured (OPENAI_BASE_URL/OPENAI_API_KEY, or the OPENAI_SUMMARY_* equivalents).');
        }

        $this->lastCallRetried = false;
        $options = $this->sized($options);

        $response = $this->sendRecorded($messages, $options, true, $context, 1);

        if ($this->refusedJsonMode($response)) {
            // Codes and sizes only: neither the prompt nor the model's text is ever logged.
            Log::warning('AI provider refused JSON mode; retrying once as plain text.', [
                'error_code' => $response->json('error.code'),
                'model' => $this->model(),
                'max_tokens' => $options['max_completion_tokens'] ?? null,
                'failed_generation_length' => mb_strlen((string) $response->json('error.failed_generation')),
            ]);

            $this->lastCallRetried = true;
            $response = $this->sendRecorded($messages, $options, false, $context, 2);
        }

        if (! $response->successful()) {
            // HTTP status as the exception code, so a caller can tell a rate limit (429) from a hard failure.
            // Retry-After in seconds when the provider sends it (only the numeric form is used).
            $retryAfter = $response->header('Retry-After');

            throw new AiGenerationException(
                // The provider's own reason (e.g. Groq's token-limit message) makes the log actionable.
                rtrim("AI provider returned HTTP {$response->status()}. ".mb_substr((string) ($response->json('error.message') ?? ''), 0, 300)),
                $response->status(),
                retryAfter: is_numeric($retryAfter) ? max(0, (int) ceil((float) $retryAfter)) : null,
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new AiGenerationException('AI provider response had no message content.');
        }

        $decoded = json_decode($content, true);

        // Plain-text replies (the retry, or a model that wraps JSON in prose or a code fence).
        if (! is_array($decoded)) {
            $decoded = $this->firstJsonObject($content);
        }

        if (! is_array($decoded)) {
            throw new AiGenerationException('AI provider did not return a valid JSON object.');
        }

        return $decoded;
    }

    /**
     * One request and one usage row for its response (a refused JSON-mode attempt and the retry are
     * two). Recording is guarded inside the recorder: it can never fail or change the call.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $context
     */
    private function sendRecorded(array $messages, array $options, bool $jsonMode, array $context, int $attempt): Response
    {
        $started = hrtime(true);
        $response = $this->send($messages, $options, $jsonMode);

        app(AiUsageRecorder::class)->record($response, $context, $this->model(), $attempt, (int) round((hrtime(true) - $started) / 1_000_000));

        return $response;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function send(array $messages, array $options, bool $jsonMode): Response
    {
        $body = [
            'model' => $this->setting('model'),
            'messages' => $messages,
            'temperature' => 0.4,
        ] + ($jsonMode ? ['response_format' => ['type' => 'json_object']] : []) + $options;

        try {
            return Http::withToken((string) $this->setting('api_key'))
                ->baseUrl((string) $this->setting('base_url'))
                ->timeout((int) $this->setting('timeout'))
                ->post('/chat/completions', $body);
        } catch (Throwable $e) {
            throw new AiGenerationException('Could not reach the AI provider.', previous: $e);
        }
    }

    /** Groq's "the JSON you produced does not validate" refusal: HTTP 400, error.code json_validate_failed. */
    private function refusedJsonMode(Response $response): bool
    {
        return $response->status() === 400 && $response->json('error.code') === 'json_validate_failed';
    }

    private function isReasoningModel(): bool
    {
        return str_contains(strtolower((string) $this->setting('model')), 'gpt-oss');
    }

    /**
     * The request's token limit and reasoning effort, from the caller's answer budget.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sized(array $options): array
    {
        $options['max_completion_tokens'] ??= self::DEFAULT_ANSWER_TOKENS;

        if ($this->isReasoningModel() && ! isset($options['reasoning_effort'])) {
            // The caller did not size for reasoning: low effort, and room to think.
            $options['reasoning_effort'] = 'low';
            $options['max_completion_tokens'] += self::REASONING_HEADROOM_TOKENS;
        }

        return $options;
    }

    /**
     * The first complete {...} object in a text, found by scanning (braces inside
     * strings do not count), so prose or a markdown fence around it is ignored.
     *
     * @return array<string, mixed>|null
     */
    private function firstJsonObject(string $text): ?array
    {
        $length = strlen($text);

        for ($start = strpos($text, '{'); $start !== false; $start = strpos($text, '{', $start + 1)) {
            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}' && --$depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                    if (is_array($decoded)) {
                        return $decoded;
                    }

                    break;
                }
            }
        }

        return null;
    }

    /**
     * No profile injected at all -> read the shared `config('ai.*')` live,
     * exactly as before this class took a constructor argument.
     *
     * A profile WAS injected (e.g. `config('ai.summary')`) -> read only
     * from it, key present or not. Deliberately NOT `$this->config[$key]
     * ?? config("ai.{$key}")`: that would silently reach back into the
     * shared draft credential for whichever piece of the summary profile
     * happens to be unset, which is exactly the cross-contamination this
     * split exists to prevent — a half-configured `ai.summary` (say,
     * `OPENAI_SUMMARY_API_KEY` set but not `OPENAI_SUMMARY_BASE_URL`)
     * would otherwise silently pair the summary key with the draft
     * feature's endpoint instead of failing closed. The env()-level
     * fallback ("leave OPENAI_SUMMARY_* blank to share the draft key") is
     * already fully resolved once, at boot, inside config/ai.php's own
     * `env('OPENAI_SUMMARY_BASE_URL', env('OPENAI_BASE_URL'))` cascade —
     * this method must not redo that fallback a second time at read time.
     */
    private function setting(string $key): mixed
    {
        if ($this->config === null) {
            return config("ai.{$key}");
        }

        return $this->config[$key] ?? null;
    }
}
