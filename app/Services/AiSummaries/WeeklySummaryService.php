<?php

namespace App\Services\AiSummaries;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\AiSummary;
use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use App\Services\Ai\OpenAiCompatibleClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * S5-03/S5-04/S5-05 / FR-21: the natural-language weekly summary.
 *
 * Reuses `OpenAiCompatibleClient` — the provider client, JSON-mode
 * request shape, and fail-closed exception contract already exist from
 * Sprint 3's AI draft feature (S3-06). This is a new PROMPT over that
 * same client, not a new integration: the scope here is the payload
 * (this week's adherence, alerts, and progress) and the validation of a
 * different shape of output (one sentence of prose, not a structured
 * meal plan).
 *
 * BR-6's rule — "clinical safety checks... enforced in code, never left
 * to prompt instructions" — was written for meal-plan food choices, which
 * have a checkable ground truth (a food_id either exists or it doesn't).
 * Free-text prose has no equivalent fact to validate against, so the
 * code-enforced boundary here is different in kind: the LLM is given
 * ONLY numbers this system already computed and trusts (adherence rate,
 * alert counts, weight change) and is instructed not to give medical or
 * dietary advice — it may comment on logging behaviour and progress, not
 * prescribe. That instruction is enforced by keeping the output short and
 * length-bounded and by never persisting a summary that fails the
 * non-empty/length check, but — unlike a food_id — nothing here can
 * mechanically verify the model didn't drift into advice anyway. Flagged
 * as a real limitation, not silently treated as equivalent to S3-06's
 * validation.
 */
class WeeklySummaryService
{
    private const MIN_SUMMARY_LENGTH = 20;

    private const MAX_SUMMARY_LENGTH = 700;

    public function __construct(
        private readonly AdherenceService $adherence,
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    /**
     * Generates (or regenerates) the summary for one ISO week and
     * persists it. Idempotent per (subscriber, week_start) — re-running
     * the job for an already-summarised week replaces that row rather
     * than accumulating a second one for the same period.
     */
    public function generateForWeek(Subscriber $subscriber, CarbonImmutable $weekStart): AiSummary
    {
        $weekEnd = $weekStart->addDays(6);
        $metrics = $this->collectMetrics($subscriber, $weekStart, $weekEnd);

        $text = null;
        if ($this->llm->isConfigured()) {
            $text = $this->tryGenerateWithLlm($metrics);
        }

        $isFallback = $text === null;
        if ($isFallback) {
            $text = $this->templatedFallback($metrics);
        }

        // `whereDate`, not `updateOrCreate(['week_start' => ...])`: a
        // plain equality match against a `date`-cast column is not
        // reliable across drivers — the string Eloquent round-trips
        // through the cast is not guaranteed byte-identical between
        // MySQL and SQLite, so the match finds the existing row on one
        // and misses it on the other, appending a duplicate instead of
        // replacing it. Same fix as `MeasurementController`'s
        // `recorded_at` lookup (S4-02) for the identical reason.
        $existing = $subscriber->aiSummaries()
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $attributes = ['summary_text' => $text, 'is_fallback' => $isFallback, 'generated_at' => now()];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return $subscriber->aiSummaries()->create($attributes + ['week_start' => $weekStart->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function collectMetrics(Subscriber $subscriber, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $adherence = $this->adherence->summary($subscriber, $weekStart->toDateString(), $weekEnd->toDateString());

        $alertCounts = $subscriber->alerts()
            ->whereBetween('created_at', [$weekStart, $weekEnd->endOfDay()])
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $readings = $subscriber->bodyCompositionReadings()
            ->whereBetween('recorded_at', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->reorder('recorded_at')
            ->get(['weight_kg']);

        return [
            'adherence_percent' => $adherence['adherence_percent'],
            'adherence_status' => $adherence['status'],
            'total_logs' => $adherence['total_logs'],
            'alert_counts' => $alertCounts->all(),
            // Signed: negative is a loss, positive a gain — the fallback
            // template and the LLM prompt both read this the same way.
            'weight_change_kg' => $readings->count() >= 2
                ? round((float) $readings->last()->weight_kg - (float) $readings->first()->weight_kg, 1)
                : null,
        ];
    }

    /** @param array<string, mixed> $metrics */
    private function tryGenerateWithLlm(array $metrics): ?string
    {
        try {
            $response = $this->llm->chatJson([
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => json_encode($metrics, JSON_UNESCAPED_UNICODE)],
            ]);
        } catch (AiGenerationException $e) {
            Log::warning('Weekly summary: LLM call failed, falling back to templated summary.', ['error' => $e->getMessage()]);

            return null;
        }

        $summary = $response['summary'] ?? null;

        if (! is_string($summary) || mb_strlen(trim($summary)) < self::MIN_SUMMARY_LENGTH || mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            Log::warning('Weekly summary: LLM response failed validation, falling back to templated summary.', ['response' => $response]);

            return null;
        }

        return trim($summary);
    }

    /**
     * Output is ALWAYS Arabic, regardless of who reads it.
     *
     * Not a locale-following decision: this runs from a scheduled job
     * (`ai-summaries:generate-weekly`) with no request and therefore no
     * locale to follow, and nothing in the schema records a per-user
     * language preference. Arabic is the product's own language (an
     * Arabic-market platform per the MVP spec), so the summary is written
     * once, in Arabic, and stored that way — rather than being generated
     * in whatever language the model happens to default to, which is what
     * produced English notes on the Arabic dashboard before this.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a clinical assistant writing a SHORT weekly progress note
        for a nutritionist about one of their clients — never for the
        client directly, and never a final assessment.

        WRITE THE SUMMARY IN ARABIC. The `summary` value must be Modern
        Standard Arabic, in a professional clinical register addressed to
        the nutritionist. Keep numbers in Western digits (e.g. 88%,
        -0.8 كجم). This is required even though these instructions and
        the input data are in English.

        You will be given this week's numbers only: an adherence
        percentage (or null if nothing was logged), an adherence status
        (stable, declining, or stopped_logging), a count of alerts raised
        by type, and a signed weight change in kg (or null if not enough
        data). Use ONLY these numbers — never invent a figure that was not
        given to you.

        Write ONE short paragraph (2-4 sentences): name one genuine
        strength this week, then one area to improve. Do NOT give medical
        or dietary advice, do NOT suggest specific foods or calorie
        targets, and do NOT diagnose anything — comment only on logging
        behaviour and observed progress. If adherence_percent is null,
        say plainly that there is not enough data yet rather than
        guessing.

        Respond with a single JSON object, no prose, no markdown, matching
        exactly: {"summary": "..."}
        PROMPT;
    }

    /**
     * S5-05: deterministic, built directly from the same metrics the LLM
     * would have received — never fails, never calls out.
     *
     * Arabic for the same reason the prompt is (see `systemPrompt()`):
     * the fallback stands in for the LLM's output, so it has to read as
     * the same note in the same language. An English fallback appearing
     * whenever the provider is unreachable would make the language of a
     * client's summary depend on whether Groq happened to answer.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function templatedFallback(array $metrics): string
    {
        if ($metrics['total_logs'] === 0) {
            return 'لم تُسجَّل أي وجبات هذا الأسبوع، فلا توجد بيانات كافية بعد لإعداد ملخص تقدّم.';
        }

        $percent = $metrics['adherence_percent'];
        $sentence = "بلغ الالتزام بالخطة هذا الأسبوع {$percent}%، بتسجيل {$metrics['total_logs']} وجبة.";

        $sentence .= match ($metrics['adherence_status']) {
            'declining' => ' يمثّل هذا تراجعاً عن الأسبوع السابق يستحق المتابعة.',
            'stopped_logging' => ' التسجيل غير منتظم — قد تفيد متابعة مباشرة مع المريض.',
            default => ' وقد ظل هذا المستوى مستقراً.',
        };

        if ($metrics['weight_change_kg'] !== null) {
            $direction = $metrics['weight_change_kg'] < 0 ? 'انخفض' : 'ارتفع';
            $sentence .= sprintf(' %s الوزن %.1f كجم هذا الأسبوع.', $direction, abs($metrics['weight_change_kg']));
        }

        return $sentence;
    }
}
