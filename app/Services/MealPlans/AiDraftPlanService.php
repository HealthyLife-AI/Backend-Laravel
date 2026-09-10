<?php

namespace App\Services\MealPlans;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * S3-06/S3-07 / F-5 (PRD, P1): "Suggest a starting plan."
 *
 * Two generation paths, not one:
 *  - An LLM path (config/ai.php — an OpenAI-compatible endpoint, Groq's
 *    free tier by default) that picks from this client's own approved,
 *    allergy-safe foods and is asked to vary the headline choice per
 *    meal, the same way the rule-based path enforces it.
 *  - The original rule-based path (calorie-fit ranking), used whenever
 *    no provider is configured, the request fails, or the response
 *    doesn't hold up to validation.
 * The LLM path is attempted first only when a provider is actually
 * configured (`OpenAiCompatibleClient::isConfigured()`) — with nothing
 * configured this behaves exactly as it did before this file could call
 * an LLM at all. Every LLM response is revalidated against the same
 * safe-food set before it's trusted: a `food_id` the model invents
 * (hallucinated, not in the list it was given) discards the *entire*
 * response rather than persisting a plan referencing a food that isn't
 * actually safe or, worse, doesn't exist — F-5's own validation note
 * ("this feature was decided by the team, not yet confirmed") is
 * exactly the kind of feature that shouldn't get to silently degrade
 * data integrity if the provider misbehaves.
 *
 * BR-6/BR-10 ("AI output is always an editable assistant artifact — never
 * auto-sent to a client"): both paths return a plan with `status =
 * 'draft'` and `is_ai_draft = true`, and nothing in `MealPlanService` has
 * a second path to `active` — the nutritionist must call the same
 * `activate()` every hand-built plan goes through. That's enforced by
 * there being no other door, not by a rule either path has to remember
 * to follow.
 *
 * Allergy handling is a best-effort case-insensitive substring exclusion
 * against each candidate food's name (English and Arabic) — a real
 * safety net, but explicitly not a certified allergen-matching system;
 * it can't catch an allergen an ingredient name doesn't mention (e.g. a
 * hidden dairy component). The nutritionist reviews every draft before
 * it can reach a client — this is a starting point, not a guarantee.
 * Excluding allergens from the candidate list *before* either path runs
 * means the LLM is never even shown an unsafe food to begin with.
 */
class AiDraftPlanService
{
    /**
     * Standard clinical rule-of-thumb calorie split across the day's
     * four meal slots — not derived from this client's data, a common
     * default any plan has to start from somewhere. Shared by both
     * generation paths so an LLM-drafted plan and a rule-based one are
     * held to the same per-meal targets.
     */
    private const MEAL_CALORIE_SHARE = [
        'breakfast' => 0.25,
        'snack' => 0.10,
        'lunch' => 0.35,
        'dinner' => 0.30,
    ];

    /** Foods sent to the LLM as its menu — capped so a large catalog never blows up the prompt. */
    private const MAX_CANDIDATE_FOODS = 200;

    public function __construct(
        private readonly MealPlanService $plans,
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    public function generateDraft(Subscriber $subscriber, User $creator): MealPlan
    {
        $profile = $subscriber->healthProfile;

        abort_if($profile === null, 422, 'This client needs a health profile (calorie needs, allergies) before an AI draft can be generated.');

        $dailyCalories = (int) $profile->daily_calorie_needs;
        $allergyTerms = array_map('mb_strtolower', $profile->allergies ?? []);

        $approvedFoods = Food::approved()->get();
        $safeFoods = $approvedFoods->reject(fn (Food $food) => $this->containsAllergen($food, $allergyTerms));

        abort_if($safeFoods->isEmpty(), 422, 'No approved foods are free of this client\'s allergies — add more foods to the database before drafting.');

        $meals = null;

        if ($this->llm->isConfigured()) {
            $meals = $this->tryGenerateWithLlm($safeFoods, $dailyCalories);
        }

        if ($meals === null) {
            $meals = $this->generateRuleBasedMeals($safeFoods, $dailyCalories);
        }

        return $this->plans->create($subscriber, ['meals' => $meals], $creator, isAiDraft: true);
    }

    /**
     * Same shape `generateRuleBasedMeals` returns. Null on any failure —
     * network, malformed JSON, or content that doesn't pass validation.
     * Never partially trusted.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function tryGenerateWithLlm(Collection $safeFoods, int $dailyCalories): ?array
    {
        $candidates = $safeFoods->take(self::MAX_CANDIDATE_FOODS);

        try {
            $response = $this->llm->chatJson([
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($candidates, $dailyCalories)],
            ]);
        } catch (AiGenerationException $e) {
            Log::warning('AI draft: LLM call failed, falling back to rule-based generation.', ['error' => $e->getMessage()]);

            return null;
        }

        $validated = $this->validateLlmMeals($response, $candidates);

        if ($validated === null) {
            Log::warning('AI draft: LLM response failed validation, falling back to rule-based generation.', ['response' => $response]);

            return null;
        }

        Log::info('AI draft: generated via LLM.', ['meal_count' => count($validated)]);

        return $validated;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a clinical nutrition assistant drafting a starting meal plan
        for a nutritionist to review and edit — never a final plan. Choose
        foods ONLY from the numbered list of "id" values the user message
        gives you; never invent a food or an id that isn't in that list.

        Respond with a single JSON object, no prose, no markdown, matching
        exactly:
        {"meals": [{"name": "breakfast", "items": [{"food_id": 1, "quantity_grams": 150, "alternatives": [{"food_id": 2, "quantity_grams": 120}]}]}]}

        Rules:
        - "name" must be exactly one of: breakfast, snack, lunch, dinner — one meal per name, in that order.
        - Every meal needs exactly one item (the planned choice) with 0-2 alternatives.
        - "food_id" must be an id from the provided list.
        - "quantity_grams" must be a number between 50 and 500.
        - Use a DIFFERENT food_id as the planned item in every meal — do not repeat the same headline food across meals (alternatives may repeat).
        - Try to make each meal's planned item's calories land close to that meal's target calories given in the user message.
        PROMPT;
    }

    private function userPrompt(Collection $candidateFoods, int $dailyCalories): string
    {
        $foods = $candidateFoods->map(fn (Food $food) => [
            'id' => $food->id,
            'name' => $food->name_en ?? $food->name_ar,
            'calories_per_100g' => (float) $food->calories_per_100g,
            'protein_g_per_100g' => (float) $food->protein_g_per_100g,
            'carbs_g_per_100g' => (float) $food->carbs_g_per_100g,
            'fat_g_per_100g' => (float) $food->fat_g_per_100g,
        ])->values()->all();

        $mealTargets = [];
        foreach (self::MEAL_CALORIE_SHARE as $mealName => $share) {
            $mealTargets[$mealName] = round($dailyCalories * $share);
        }

        return json_encode([
            'daily_calorie_target' => $dailyCalories,
            'meal_calorie_targets' => $mealTargets,
            'available_foods' => $foods,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Re-derives the exact same shape `generateRuleBasedMeals` produces
     * from whatever the LLM returned — rejecting the whole response the
     * moment anything doesn't check out, rather than keeping the parts
     * that happen to look valid. A model that got 3 of 4 meals right and
     * hallucinated the 4th is still a model that can't be trusted for
     * the 3 without re-verifying them individually anyway; discarding
     * everything and falling back is simpler and no less safe.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function validateLlmMeals(array $response, Collection $candidateFoods): ?array
    {
        $validFoodIds = $candidateFoods->pluck('id')->all();
        $allowedMealNames = array_keys(self::MEAL_CALORIE_SHARE);

        $rawMeals = $response['meals'] ?? null;
        if (! is_array($rawMeals) || $rawMeals === []) {
            return null;
        }

        $seenMealNames = [];
        $plannedFoodIds = [];
        $meals = [];

        foreach ($rawMeals as $rawMeal) {
            if (! is_array($rawMeal)) {
                return null;
            }

            $name = $rawMeal['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $allowedMealNames, true) || in_array($name, $seenMealNames, true)) {
                return null;
            }

            $rawItems = $rawMeal['items'] ?? null;
            if (! is_array($rawItems) || count($rawItems) !== 1) {
                return null;
            }

            $item = $this->validateLlmItem($rawItems[0] ?? null, $validFoodIds);
            if ($item === null || in_array($item['food_id'], $plannedFoodIds, true)) {
                return null;
            }

            $alternatives = [];
            foreach (($rawItems[0]['alternatives'] ?? []) as $rawAlt) {
                if (count($alternatives) >= 2) {
                    break;
                }
                $alt = $this->validateLlmItem($rawAlt, $validFoodIds);
                if ($alt === null) {
                    return null;
                }
                $alternatives[] = $alt;
            }

            $seenMealNames[] = $name;
            $plannedFoodIds[] = $item['food_id'];
            $meals[] = ['name' => $name, 'items' => [['food_id' => $item['food_id'], 'quantity_grams' => $item['quantity_grams'], 'alternatives' => $alternatives]]];
        }

        return $meals;
    }

    /** @return array{food_id: int, quantity_grams: float}|null */
    private function validateLlmItem(mixed $raw, array $validFoodIds): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $foodId = $raw['food_id'] ?? null;
        $quantity = $raw['quantity_grams'] ?? null;

        // is_numeric + cast, not is_int: a model returning "food_id": 12.0
        // is still unambiguously id 12, not a reason to discard an
        // otherwise-valid response.
        if (! is_numeric($foodId) || (int) $foodId != $foodId) {
            return null;
        }
        $foodId = (int) $foodId;

        if (! in_array($foodId, $validFoodIds, true)) {
            return null;
        }

        if (! is_numeric($quantity) || $quantity < 50 || $quantity > 500) {
            return null;
        }

        return ['food_id' => $foodId, 'quantity_grams' => round((float) $quantity, 1)];
    }

    /** @return array<int, array<string, mixed>> */
    private function generateRuleBasedMeals(Collection $safeFoods, int $dailyCalories): array
    {
        $meals = [];
        // Every meal's PLANNED item must be a food no earlier meal in
        // this same draft already planned. Without this, a quantity
        // range wide enough to hit any calorie target (50-500g) lets
        // almost any food "fit" almost any meal near-perfectly, and
        // ranking by fit alone degenerates into picking the same one or
        // two foods for breakfast, lunch, and dinner — a real defect
        // caught by actually looking at a generated draft, not a
        // hypothetical. Alternatives may still repeat across meals
        // (a grain as a side option more than once in a day is normal);
        // only the headline choice has to vary.
        $plannedFoodIds = [];
        foreach (self::MEAL_CALORIE_SHARE as $mealName => $share) {
            $mealTarget = $dailyCalories * $share;
            $candidates = $this->rankByCalorieFit($safeFoods, $mealTarget, $plannedFoodIds)->values();

            if ($candidates->isEmpty()) {
                // Every safe food is already this draft's planned choice
                // somewhere else — fall back to ranking without the
                // diversity exclusion rather than leaving the meal empty.
                $candidates = $this->rankByCalorieFit($safeFoods, $mealTarget)->values();
            }

            if ($candidates->isEmpty()) {
                continue;
            }

            $planned = $candidates->first();
            $plannedFoodIds[] = $planned['food']->id;
            $alternatives = $candidates->slice(1, 2); // up to 2 alternatives

            $meals[] = [
                'name' => $mealName,
                'items' => [[
                    'food_id' => $planned['food']->id,
                    'quantity_grams' => $planned['quantity_grams'],
                    'alternatives' => $alternatives->map(fn (array $c) => [
                        'food_id' => $c['food']->id,
                        'quantity_grams' => $c['quantity_grams'],
                    ])->all(),
                ]],
            ];
        }

        return $meals;
    }

    private function containsAllergen(Food $food, array $allergyTerms): bool
    {
        if ($allergyTerms === []) {
            return false;
        }

        $haystack = mb_strtolower(($food->name_en ?? '').' '.($food->name_ar ?? ''));

        foreach ($allergyTerms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * For each candidate food, the gram quantity that would hit the
     * meal's calorie target exactly from that food alone, clamped to a
     * plausible single-food portion (50g-500g) — then sorted so the
     * closest actual fit (least clamping needed) comes first.
     *
     * @param  array<int>  $excludeFoodIds
     * @return Collection<int, array{food: Food, quantity_grams: float, fit: float}>
     */
    private function rankByCalorieFit(Collection $foods, float $mealTarget, array $excludeFoodIds = []): Collection
    {
        return $foods
            ->reject(fn (Food $food) => in_array($food->id, $excludeFoodIds, true))
            ->map(function (Food $food) use ($mealTarget) {
                $caloriesPer100g = (float) $food->calories_per_100g;
                if ($caloriesPer100g <= 0) {
                    return null;
                }

                $rawGrams = ($mealTarget / $caloriesPer100g) * 100;
                $quantityGrams = max(50, min(500, $rawGrams));
                $achievedCalories = $caloriesPer100g * $quantityGrams / 100;

                return [
                    'food' => $food,
                    'quantity_grams' => round($quantityGrams, 1),
                    'fit' => abs($achievedCalories - $mealTarget),
                ];
            })
            ->filter()
            ->sortBy('fit');
    }
}
