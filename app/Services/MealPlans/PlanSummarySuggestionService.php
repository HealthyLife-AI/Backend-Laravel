<?php

namespace App\Services\MealPlans;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\Nutrition\CalorieTarget;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Support\Facades\Log;

/**
 * Item D: a suggested plan summary for the nutritionist to edit — never
 * saved by this call, and never the source of a number.
 *
 * Every figure in the result is computed HERE, from the plan's own
 * items, and the opening line is assembled by this service. The model is
 * asked only to name the pattern ("قليل الكربوهيدرات، عالي البروتين")
 * and add one follow-up note; a reply that introduces any digit not in
 * the computed first line is rejected outright. An LLM that is confident
 * and wrong about a macro percentage is worse than no suggestion.
 *
 * What is sent: the computed figures, the goal type, the allergy GROUPS
 * and the approved target. What is never sent: the patient's name or
 * phone, and every free-text field in the file — nutritionist_notes,
 * lab_notes and any existing plan_summary.
 */
class PlanSummarySuggestionService
{
    private const MAX_MODEL_CHARS = 250;

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly MealPlanCalculatorService $calculator,
    ) {}

    /**
     * @return array{suggested_summary: string, suggested_approved_calories: ?int, is_fallback: bool}
     */
    public function suggest(MealPlan $plan, Subscriber $subscriber): array
    {
        $figures = $this->figures($plan);
        $headline = $this->headline($figures);
        $approved = $plan->approved_calories !== null
            ? (int) $plan->approved_calories
            : CalorieTarget::effective($subscriber);

        if (! $this->llm->isConfigured()) {
            return $this->fallback($headline, $approved, 'not_configured');
        }

        try {
            $reply = $this->llm->chatJson([
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($figures, $subscriber, $approved)],
            ], ['max_completion_tokens' => 600]);
        } catch (AiGenerationException $e) {
            // The message, never the plan: nothing from the file reaches a log line.
            Log::warning('Plan summary suggestion: provider failed.', ['reason' => $e->getMessage()]);

            return $this->fallback($headline, $approved, 'provider_failed');
        }

        $note = trim((string) ($reply['summary'] ?? ''));

        if ($note === '' || mb_strlen($note) > self::MAX_MODEL_CHARS || $this->inventsNumbers($note, $headline)) {
            Log::info('Plan summary suggestion: reply rejected.', ['reason' => $note === '' ? 'empty' : 'numbers_or_length']);

            return $this->fallback($headline, $approved, 'rejected');
        }

        return [
            'suggested_summary' => $headline."\n".$note,
            'suggested_approved_calories' => $approved,
            'is_fallback' => false,
        ];
    }

    /**
     * Average daily calories and each macro's share OF CALORIES
     * (protein/carbs 4 kcal/g, fat 9 — the same factors the designer
     * shows), over the plan's own per-day totals.
     *
     * @return array{kcal: int, protein: int, carbs: int, fat: int}
     */
    private function figures(MealPlan $plan): array
    {
        $byDay = $this->calculator->planSummaryByDay($plan);
        $days = max(1, count($byDay));

        $calories = array_sum(array_column($byDay, 'calories')) / $days;
        $protein = array_sum(array_column($byDay, 'protein_g')) / $days;
        $carbs = array_sum(array_column($byDay, 'carbs_g')) / $days;
        $fat = array_sum(array_column($byDay, 'fat_g')) / $days;

        $macroCalories = ($protein * 4) + ($carbs * 4) + ($fat * 9);
        $share = fn (float $grams, int $factor) => $macroCalories > 0 ? (int) round($grams * $factor / $macroCalories * 100) : 0;

        return [
            'kcal' => (int) round($calories),
            'protein' => $share($protein, 4),
            'carbs' => $share($carbs, 4),
            'fat' => $share($fat, 9),
        ];
    }

    /** @param array{kcal: int, protein: int, carbs: int, fat: int} $f */
    private function headline(array $f): string
    {
        return "{$f['kcal']} سعرة · بروتين {$f['protein']}% · كربوهيدرات {$f['carbs']}% · دهون {$f['fat']}%";
    }

    /** @return array{suggested_summary: string, suggested_approved_calories: ?int, is_fallback: bool} */
    private function fallback(string $headline, ?int $approved, string $reason): array
    {
        Log::info('Plan summary suggestion: first line only.', ['reason' => $reason]);

        return [
            'suggested_summary' => $headline,
            'suggested_approved_calories' => $approved,
            'is_fallback' => true,
        ];
    }

    /**
     * Any digit the model produced that is not already in the computed
     * first line means it invented a figure — reject the whole reply
     * rather than try to repair it.
     */
    private function inventsNumbers(string $note, string $headline): bool
    {
        preg_match_all('/\d+/u', $headline, $allowed);
        preg_match_all('/\d+/u', $note, $found);

        return array_diff($found[0], $allowed[0]) !== [];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You write one short note for a nutritionist's own file, in Arabic.

            You are given a meal plan's computed figures. Do NOT restate them,
            do NOT compute anything, and do NOT write ANY digit at all.

            Reply as JSON: {"summary": "..."}

            The value must be at most 250 characters, in Arabic, and contain:
            - the pattern of the plan in a few words (e.g. "قليل الكربوهيدرات، عالي البروتين");
            - one short follow-up note for the next visit.

            No greetings, no patient name, no medical claims, no numbers.
            PROMPT;
    }

    /** @param array{kcal: int, protein: int, carbs: int, fat: int} $f */
    private function userPrompt(array $f, Subscriber $subscriber, ?int $approved): string
    {
        $goal = $subscriber->patientGoal?->goal_type ?? $subscriber->goal ?? 'unspecified';
        $groups = app(HealthRecordService::class)->allergyGroups($subscriber);

        $lines = [
            "goal: {$goal}",
            "daily calories: {$f['kcal']}",
            "protein share: {$f['protein']}%",
            "carb share: {$f['carbs']}%",
            "fat share: {$f['fat']}%",
            'allergy groups: '.($groups === [] ? 'none' : implode(', ', $groups)),
        ];

        if ($approved !== null) {
            $lines[] = "approved target: {$approved}";
        }

        return implode("\n", $lines);
    }
}
