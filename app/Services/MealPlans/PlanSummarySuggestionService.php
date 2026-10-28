<?php

namespace App\Services\MealPlans;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\Nutrition\CalorieTarget;
use App\Services\Nutrition\MealPlanCalculatorService;
use App\Support\ArabicText;
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
     * `fallback_reason` says why only the first line came back: not_configured | provider_failed | empty |
     * rejected_length | rejected_digit | macro_label (null when the model's note was used).
     *
     * @return array{suggested_summary: string, suggested_approved_calories: ?int, is_fallback: bool, fallback_reason: ?string}
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
            ], ['max_completion_tokens' => 600], ['feature' => 'plan_summary', 'nutritionist_id' => $subscriber->nutritionist_id, 'subscriber_id' => $subscriber->id]);
        } catch (AiGenerationException $e) {
            // The message, never the plan: nothing from the file reaches a log line.
            Log::warning('Plan summary suggestion: provider failed.', ['reason' => $e->getMessage()]);

            return $this->fallback($headline, $approved, 'provider_failed');
        }

        $note = trim((string) ($reply['summary'] ?? ''));

        $reason = match (true) {
            $note === '' => 'empty',
            mb_strlen($note) > self::MAX_MODEL_CHARS => 'rejected_length',
            $this->inventsNumbers($note, $headline) => 'rejected_digit',
            $this->claimsMacroPattern($note, $figures['labels']) => 'macro_label',
            default => null,
        };

        if ($reason !== null) {
            Log::info('Plan summary suggestion: reply rejected.', ['reason' => $reason]);

            return $this->fallback($headline, $approved, $reason);
        }

        return [
            'suggested_summary' => $headline."\n".$note,
            'suggested_approved_calories' => $approved,
            'is_fallback' => false,
            'fallback_reason' => null,
        ];
    }

    /**
     * Average daily calories and each macro's share OF CALORIES
     * (protein/carbs 4 kcal/g, fat 9 — the same factors the designer
     * shows), over the plan's own per-day totals.
     *
     * Plus `labels`: whether the plan may be CALLED low-carb / high-protein /
     * low-fat, decided here from the shares against config('nutrition.macro_labels'),
     * never by the model.
     *
     * @return array{kcal: int, protein: int, carbs: int, fat: int, labels: array{low_carb: bool, high_protein: bool, low_fat: bool}}
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

        $shares = ['protein' => $share($protein, 4), 'carbs' => $share($carbs, 4), 'fat' => $share($fat, 9)];
        $limits = (array) config('nutrition.macro_labels');

        return [
            'kcal' => (int) round($calories),
            'labels' => [
                'low_carb' => $shares['carbs'] < $limits['low_carb_max'],
                'high_protein' => $shares['protein'] > $limits['high_protein_min'],
                'low_fat' => $shares['fat'] < $limits['low_fat_max'],
            ],
        ] + $shares;
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
            'fallback_reason' => $reason,
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

    /**
     * Does the note call the diet low/high in a macro its computed share does not
     * back? Only low-carb, high-protein and low-fat can ever be said, and only when
     * the matching flag is true; every other pairing ("عالي الكربوهيدرات",
     * "قليل البروتين", "عالي الدهون") is refused outright.
     *
     * @param  array{low_carb: bool, high_protein: bool, low_fat: bool}  $labels
     */
    private function claimsMacroPattern(string $note, array $labels): bool
    {
        $text = (string) ArabicText::normalize($note);

        $adjectives = [
            'low' => 'قليل|قليله|منخفض|منخفضه|فقير|فقيره',
            'high' => 'عالي|عاليه|مرتفع|مرتفعه|غني|غنيه',
        ];
        $nouns = ['carb' => 'كربوهيدرات|كاربوهيدرات|كربو|نشويات', 'protein' => 'بروتين|بروتينات', 'fat' => 'دهون|دسم'];
        $allowed = ['low:carb' => $labels['low_carb'], 'high:protein' => $labels['high_protein'], 'low:fat' => $labels['low_fat']];

        foreach ($adjectives as $direction => $adjective) {
            foreach ($nouns as $macro => $noun) {
                // "قليل الكربوهيدرات" and "الكربوهيدرات القليلة": the adjective on either side of the noun.
                $pattern = '/(?<![\p{L}])(?:(?:'.$adjective.')\s+(?:ب)?(?:ال)?(?:'.$noun.')|(?:ال)?(?:'.$noun.')\s+(?:ال)?(?:'.$adjective.'))(?![\p{L}])/u';

                if (preg_match($pattern, $text) === 1 && ! ($allowed["{$direction}:{$macro}"] ?? false)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You write one short note for a nutritionist's own file, in Arabic.

            You are given a meal plan's computed figures. Do NOT restate them,
            do NOT compute anything, and do NOT write ANY digit at all.

            Reply as JSON: {"summary": "..."}

            The value must be at most 250 characters, in Arabic, and contain:
            - the pattern of the plan in a few words, ONLY from the flags you are given: say "قليل الكربوهيدرات" only when
              low_carb is true, "عالي البروتين" only when high_protein is true, "قليل الدهون" only when low_fat is true.
              A flag that is false means the plan is NOT that: never call it that, and never call a plan high in
              carbohydrates or fat, or low in protein. With no flag true, say nothing about macros or call the plan balanced;
            - one short follow-up note for the next visit.

            No greetings, no patient name, no medical claims, no numbers.
            PROMPT;
    }

    /** @param array{kcal: int, protein: int, carbs: int, fat: int} $f */
    private function userPrompt(array $f, Subscriber $subscriber, ?int $approved): string
    {
        $goal = $subscriber->patientGoal?->goal_type ?? $subscriber->goal ?? 'unspecified';
        $groups = app(HealthRecordService::class)->allergyGroups($subscriber);

        // The flags, not the shares: the model does not judge the pattern, the server did.
        $lines = [
            "goal: {$goal}",
            'low_carb: '.($f['labels']['low_carb'] ? 'true' : 'false'),
            'high_protein: '.($f['labels']['high_protein'] ? 'true' : 'false'),
            'low_fat: '.($f['labels']['low_fat'] ? 'true' : 'false'),
            'allergy groups: '.($groups === [] ? 'none' : implode(', ', $groups)),
        ];

        if ($approved !== null) {
            $lines[] = "approved target: {$approved}";
        }

        return implode("\n", $lines);
    }
}
