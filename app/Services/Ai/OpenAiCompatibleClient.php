<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiGenerationException;
use Illuminate\Support\Facades\Http;
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
    /** @param  array{base_url?: string|null, api_key?: string|null, model?: string|null, timeout?: int}|null  $config */
    public function __construct(private readonly ?array $config = null) {}

    public function isConfigured(): bool
    {
        return filled($this->setting('base_url')) && filled($this->setting('api_key'));
    }

    /**
     * Sends a chat completion request with `response_format: json_object`
     * (part of the OpenAI-compatible spec Groq also implements) and
     * returns the response content already `json_decode`d. Every
     * failure mode — network error, non-2xx, missing content, content
     * that isn't valid JSON — throws `AiGenerationException` rather
     * than returning something the caller might mistake for a real
     * result; there is no partial-success return value here.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages): array
    {
        if (! $this->isConfigured()) {
            throw new AiGenerationException('No AI provider is configured (OPENAI_BASE_URL/OPENAI_API_KEY, or the OPENAI_SUMMARY_* equivalents).');
        }

        try {
            $response = Http::withToken((string) $this->setting('api_key'))
                ->baseUrl((string) $this->setting('base_url'))
                ->timeout((int) $this->setting('timeout'))
                ->post('/chat/completions', [
                    'model' => $this->setting('model'),
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.4,
                ]);
        } catch (Throwable $e) {
            throw new AiGenerationException('Could not reach the AI provider.', previous: $e);
        }

        if (! $response->successful()) {
            throw new AiGenerationException("AI provider returned HTTP {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new AiGenerationException('AI provider response had no message content.');
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new AiGenerationException('AI provider did not return a valid JSON object.');
        }

        return $decoded;
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
