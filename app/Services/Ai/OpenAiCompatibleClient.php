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
 * same REST shape, by changing three env vars and nothing else. Kept
 * generic on purpose — F-7's weekly AI summary (not built yet) needs
 * the exact same "call a chat model, get text/JSON back" primitive.
 *
 * No SDK dependency: this is one HTTP call with a fixed shape, not
 * enough surface to justify a package over Laravel's own `Http` facade.
 */
class OpenAiCompatibleClient
{
    public function isConfigured(): bool
    {
        return filled(config('ai.base_url')) && filled(config('ai.api_key'));
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
            throw new AiGenerationException('No AI provider is configured (OPENAI_BASE_URL/OPENAI_API_KEY).');
        }

        try {
            $response = Http::withToken((string) config('ai.api_key'))
                ->baseUrl((string) config('ai.base_url'))
                ->timeout((int) config('ai.timeout'))
                ->post('/chat/completions', [
                    'model' => config('ai.model'),
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
}
