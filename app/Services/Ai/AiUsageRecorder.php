<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one ai_usage_logs row for a provider response, from its `usage` object only.
 * It never sees, and never stores, the prompt or the reply. It must never break or slow the
 * AI call: any failure here is a warning in the log (class name only) and nothing more.
 */
class AiUsageRecorder
{
    /**
     * @param  array{feature?: string, nutritionist_id?: int|null, subscriber_id?: int|null, attempt_base?: int}  $context
     */
    public function record(Response $response, array $context, ?string $model, int $attempt, int $latencyMs): void
    {
        try {
            $usage = (array) $response->json('usage');
            $feature = (string) ($context['feature'] ?? 'other');

            AiUsageLog::create([
                'feature' => in_array($feature, AiUsageLog::FEATURES, true) ? $feature : 'other',
                'nutritionist_id' => $context['nutritionist_id'] ?? null,
                'subscriber_id' => $context['subscriber_id'] ?? null,
                'model' => mb_substr((string) $model, 0, 100),
                'prompt_tokens' => $this->int($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => $this->int($usage['completion_tokens'] ?? 0),
                'reasoning_tokens' => $this->nullableInt($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
                'cached_tokens' => $this->nullableInt($usage['prompt_tokens_details']['cached_tokens'] ?? null),
                'total_tokens' => $this->int($usage['total_tokens'] ?? (($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0))),
                'attempt' => min(255, max(1, ((int) ($context['attempt_base'] ?? 0)) + $attempt)),
                'success' => $response->successful(),
                'latency_ms' => max(0, $latencyMs),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AI usage could not be recorded.', ['error' => $e::class]);
        }
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
