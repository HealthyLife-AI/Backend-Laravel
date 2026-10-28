<?php

namespace App\Services\MealPlans;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\Food;
use App\Models\FoodRegionalCost;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\HealthRecords\HealthRecordService;
use App\Services\FoodPreferences\FoodPreferenceService;
use App\Services\Nutrition\CalorieTarget;
use App\Support\RegionalCountry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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

    /**
     * Foods sent to the LLM as its menu. Kept small, and sent as compact rows:
     * with the full catalog (~7,800 foods) the old 200-food list of keyed
     * objects went past the provider's per-request token limit (Groq answered
     * HTTP 413 on every draft, so every draft fell back). 80 rows is ~2k tokens.
     */
    private const MAX_CANDIDATE_FOODS = 80;

    /** The drafting nutritionist's country and the regional cost rows for it (food id => tier); set per draft. */
    private string $costCountry = 'PS';

    /** @var array<int, string> */
    private array $regionalTiers = [];

    private const MAX_COMPLETION_TOKENS = 6000;

    /** A plated meal: a main dish and up to three sides. */
    private const MAX_ITEMS_PER_MEAL = 4;

    /** Disliked foods are dropped from the pool only while at least this many foods remain. */
    private const MIN_FOODS_AFTER_DISLIKES = 3;

    /** Longest a nutritionist is kept waiting for a rate-limit retry before the draft falls back. */
    private const MAX_INTERACTIVE_WAIT_SECONDS = 10;

    public function __construct(
        private readonly MealPlanService $plans,
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    /**
     * `$approvedCalories` is the figure the nutritionist typed in the
     * plan-decision card and has not saved yet — the draft is built to
     * the number she is deciding on, not the one on the stored plan.
     * Without it, the effective target (Item C).
     */
    public function generateDraft(Subscriber $subscriber, User $creator, ?int $approvedCalories = null, array $options = []): MealPlan
    {
        $profile = $subscriber->healthProfile;

        abort_if($profile === null, 422, 'This client needs a health profile (calorie needs, allergies) before an AI draft can be generated.');

        $dailyCalories = $approvedCalories ?? CalorieTarget::effective($subscriber) ?? (int) $profile->daily_calorie_needs;
        // Approved data only. Foods are excluded by allergen group (every class:
        // allergy, intolerance, avoid) and, for free text, by name.
        $records = app(HealthRecordService::class);
        $groups = $records->allergyGroups($subscriber);
        $allergyTerms = array_values(array_unique(array_map('mb_strtolower', array_merge($profile->allergies ?? [], $records->otherAllergyTexts($subscriber)))));

        $approvedFoods = Food::approved()->get();
        $safeFoods = $approvedFoods->reject(fn (Food $food) => array_intersect($food->allergens ?? [], $groups) !== [] || $this->containsAllergen($food, $allergyTerms));

        abort_if($safeFoods->isEmpty(), 422, 'No approved foods are free of this client\'s allergies — add more foods to the database before drafting.');

        // Phase 2, B5: soft preferences. A disliked food is left out of the menu
        // and the rule-based pool whenever enough others remain, so it is only
        // ever picked when there is no alternative; the NAMES (nothing else new)
        // also go to the model as likes / dislikes.
        $preferences = app(FoodPreferenceService::class);
        $likedNames = $preferences->namesFor($subscriber);
        $dislikedIds = \App\Models\PatientFoodPreference::query()->where('subscriber_id', $subscriber->id)->where('kind', 'dislike')->pluck('food_id')->all();
        $pool = $safeFoods->reject(fn (Food $food) => in_array($food->id, $dislikedIds, true));
        $safeFoods = $pool->count() >= self::MIN_FOODS_AFTER_DISLIKES ? $pool : $safeFoods;

        // Regional cost: a food counts as economical in the DRAFTING nutritionist's country only (never by the PS value elsewhere).
        $this->costCountry = RegionalCountry::of($creator);
        $this->regionalTiers = FoodRegionalCost::query()->where('country', $this->costCountry)->pluck('cost_tier', 'food_id')->all();

        // Phase 4, B6: soft cost / preparation preferences, only when the nutritionist ticked them.
        $softTags = [
            'economical' => (bool) ($options['prefer_economical'] ?? false),
            'easy_prep' => (bool) ($options['prefer_easy_prep'] ?? false),
        ];

        $meals = null;

        if ($this->llm->isConfigured()) {
            $meals = $this->tryGenerateWithLlm($safeFoods, $dailyCalories, $profile, $subscriber->patientGoal?->goal_type ?? $subscriber->goal, $groups, $likedNames, $softTags);
        } else {
            Log::info('AI draft: no provider configured, using rule-based generation.', ['reason' => 'not_configured']);
        }

        $isFallback = $meals === null;
        if ($isFallback) {
            $meals = $this->generateRuleBasedMeals($this->tagPreferred($safeFoods, $softTags), $dailyCalories);
        }

        // The draft carries the target it was actually built to, so the
        // designer's ring and the per-meal split agree with the plan in
        // front of her the moment it loads.
        $plan = $this->plans->create(
            $subscriber,
            ['meals' => $meals, 'approved_calories' => $dailyCalories],
            $creator,
            isAiDraft: true,
        );
        $plan->forceFill(['is_ai_fallback' => $isFallback])->save();

        return $plan;
    }

    /**
     * Same shape `generateRuleBasedMeals` returns. Null on any failure —
     * network, malformed JSON, or content that doesn't pass validation.
     * Never partially trusted.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function tryGenerateWithLlm(Collection $safeFoods, int $dailyCalories, HealthProfile $profile, ?string $goal, array $groups = [], array $foodNames = [], array $softTags = []): ?array
    {
        $candidates = $this->menuFor($safeFoods, $softTags);

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($candidates, $dailyCalories, $profile, $goal, $groups, $foodNames, $softTags)],
        ];

        // One retry for a passing hiccup, so a single click doesn't fall back:
        // a 429 whose Retry-After is short (the nutritionist is waiting), a 400
        // where the model's own JSON failed the provider's check, or an answer
        // that failed our validation (e.g. an alternative not on the menu).
        // A failed answer is never partially kept: any unknown food_id still
        // discards the whole response.
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->llm->chatJson($messages, $this->requestOptions());
            } catch (AiGenerationException $e) {
                $retryable = $attempt === 1 && (
                    ($e->getCode() === 429 && $e->retryAfter !== null && $e->retryAfter <= self::MAX_INTERACTIVE_WAIT_SECONDS)
                    || $e->getCode() === 400
                );

                if (! $retryable) {
                    Log::warning('AI draft: LLM call failed, falling back to rule-based generation.', ['reason' => 'request_failed', 'error' => $e->getMessage()]);

                    return null;
                }

                if ($e->getCode() === 429) {
                    Sleep::for(max(1, $e->retryAfter))->seconds();
                }

                continue;
            }

            $validated = $this->validateLlmMeals($response, $candidates);

            if ($validated !== null) {
                break;
            }

            if ($attempt >= 2) {
                Log::warning('AI draft: LLM response failed validation, falling back to rule-based generation.', ['reason' => 'invalid_response', 'keys' => array_keys($response)]);

                return null;
            }
        }
        Log::info('AI draft: generated via LLM.', ['meal_count' => count($validated)]);

        return $validated;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a licensed clinical nutrition assistant. You produce a
        starting meal-plan draft for a nutritionist to review and edit —
        never a final plan. Choose foods ONLY from "available_foods" in
        the user message — each food is a row whose columns are named in
        "food_columns" (the first is its id); never invent a food or an
        id that isn't in that list.

        Use every piece of the "client_profile" in the user message to
        make intelligent, clinically sound food choices:

        1. GOAL (client_profile.goal) — adapt food choices:
           - weight_loss → favour high-protein, high-fibre,
             lower-calorie-density foods; prefer grilled/baked over fried.
           - weight_gain → include calorie-dense nutritious foods, larger
             portions of complex carbs & protein.
           - muscle_gain → protein at every meal, complex carbs around
             training; see training_days_per_week / training_type.
           - weight_maintenance / health_energy → balanced variety across
             macronutrients, steady energy through the day.
           - medical_condition / other → follow the health conditions and the
             nutritionist notes closely; be conservative.

        2. HEALTH CONDITIONS — critical dietary adjustments:
           - Diabetes / insulin resistance → low glycaemic-index carbs,
             avoid simple sugars, pair carbs with protein or fat.
           - Hypertension → avoid high-sodium foods (pickles, processed
             meats); favour potassium-rich foods.
           - High cholesterol → limit saturated fat, favour fish & omega-3.
           - Kidney disease → watch protein load and potassium.
           - Gastric / IBS / reflux → avoid spicy, fried, or acidic foods.
           - Celiac disease → strictly gluten-free.
           If a condition is present, do NOT pick a food that would
           aggravate it even if it fits calorically.

        3. MEDICATIONS — common interactions to respect:
           - Blood thinners (warfarin) → moderate vitamin-K-rich greens.
           - Metformin → avoid excessive sugar.
           - Statins → avoid large amounts of grapefruit.

        4. FOOD PREFERENCES — always respect them (vegetarian, no seafood,
           lactose-free, etc.). Never pick a food that violates a stated
           preference.

        5. AGE & GENDER:
           - Older adults (>55) → more protein & calcium-rich foods.
           - Females → include iron-rich foods when appropriate.
           - Young adults → balanced macros supporting activity.

        6. ALLERGIES — a hard safety rule, above every other consideration:
           NEVER choose a food (planned item OR alternative) that contains,
           or is made or derived from, anything in "must_avoid_allergies".
           Judge by what the dish is made of, not only by its name:
           - milk / dairy / lactose → cheese (incl. akkawi, halloumi),
             labneh, yogurt, laban, butter, ghee, cream, milk-based
             desserts (muhallabia, kunafa, halawet el jibn, om ali).
           - gluten / wheat → bread (pita, khubz), manakish, pasta,
             bulgur, freekeh, couscous, kibbeh, fatteh, pastries.
           - sesame → tahini, hummus (made with tahini), mutabbal / baba
             ghanoush, halawa, za'atar blends containing sesame.
           - egg → eggs, shakshuka, dishes or pastries made with egg.
           - nuts / peanuts → nuts, baklava, nut pastes and fillings.
           - fish / seafood / shellfish → fish, shrimp, any seafood dish.
           If you are unsure whether a food is safe for a listed allergy,
           do not choose it. Choosing fewer or plainer foods is always
           better than risking an allergen.

        7. ACTIVITY LEVEL — higher activity → more complex carbs for
           energy and protein for recovery; sedentary → lighter portions.

        8. NUTRITIONIST NOTES / LAB NOTES — if the nutritionist left
           specific instructions, follow them; they override generic rules.

        Context: this is for Arab / Middle-Eastern clients. Pick foods that
        are culturally appropriate for each meal TIME:

        Meal-time guidance (important):
        - BREAKFAST: light-to-moderate items typical of an Arab breakfast —
          eggs, labneh, hummus, foul medames, cheese, bread, za'atar
          manakish, oats, fruits, juice. NEVER pick heavy rice-based or
          meat-heavy main dishes (kabsa, mandi, biryani, shawarma, grilled
          meats, stews, etc.) for breakfast.
        - SNACK: very light — fruits, nuts, yogurt, dates, a small salad,
          or a light dairy item. Keep it under 200 g.
        - LUNCH: the heaviest meal — rice dishes, grilled meats, shawarma,
          kabsa, stews, stuffed vegetables, etc. are all appropriate here.
        - DINNER: moderate — lighter than lunch but can include cooked
          dishes. Soups, grilled items, salads with protein, sandwiches,
          or a smaller portion of a main dish.

        Respond with a single JSON object, no prose, no markdown, matching
        exactly:
        {"meals": [{"name": "breakfast", "items": [
          {"food_id": 1, "quantity_grams": 200, "alternatives": [{"food_id": 2, "quantity_grams": 150}, {"food_id": 3, "quantity_grams": 120}]},
          {"food_id": 4, "quantity_grams": 60, "alternatives": [{"food_id": 5, "quantity_grams": 60}, {"food_id": 6, "quantity_grams": 50}]}
        ]}]}

        Rules:
        - "name" must be exactly one of: breakfast, snack, lunch, dinner — one meal per name, in that order.
        - Build each meal like a real plate: breakfast, lunch and dinner have 2-3 items (a main dish plus sides such as bread, salad, yogurt or vegetables); the snack has 1-2 items.
        - EVERY item must have EXACTLY 2 alternatives: different foods from the list that can replace it at that meal time, with quantities chosen so their calories are close to the item they replace.
        - EVERY "food_id" — planned items AND alternatives — must be an id from "available_foods". A food that is not in that list must never appear anywhere in the answer, even as an alternative; the whole answer is rejected if one does.
        - "quantity_grams" must be a number between 10 and 500.
        - Never use the same food_id twice as a planned item anywhere in the day, and never repeat a food inside one meal (an alternative may reappear in another meal).
        - The calories of a meal's planned items together should land close to that meal's target calories given in the user message.
        - Respect the meal-time guidance above — a food that fits calorically but is wrong for the time of day is a bad pick.
        - Never choose a food that contains or derives from anything in must_avoid_allergies (rule 6).
        - Consider the client's health conditions and goal when choosing between foods of similar caloric value — pick the one that better serves the client's health situation.
        - client_profile.liked_foods / disliked_foods are SOFT preferences, by name: prefer a liked food when it fits, and do not choose a disliked one when another food fits as well. They never override an allergy, the calorie targets or the rules above.
        - "soft_constraints" (when present) are SOFT preferences too. prefer_economical_foods: prefer foods whose cost_tier is "economical" over "premium". prefer_no_cook_or_easy_prep_foods: prefer foods whose prep_level is "no_cook" or "easy" over "normal". A null tag means not reviewed: neither prefer nor avoid it. Never at the cost of an allergy, the calorie targets or the rules above.
        PROMPT;
    }

    /**
     * A plated day (up to ~36 foods with alternatives) is a long answer. A
     * reasoning model spends tokens thinking first; with the provider's
     * default output limit the JSON was cut off and Groq refused it (400
     * "Failed to validate JSON"). Room for the answer, and low reasoning effort
     * on gpt-oss models (fewer tokens, faster, less of the per-minute quota).
     *
     * @return array<string, mixed>
     */
    private function requestOptions(): array
    {
        $options = ['max_completion_tokens' => self::MAX_COMPLETION_TOKENS];

        if (str_contains((string) config('ai.model'), 'gpt-oss')) {
            $options['reasoning_effort'] = 'low';
        }

        return $options;
    }

    /**
     * Phase 4, B6: with a cost / preparation preference ticked, the foods that
     * match it come first (stable: the order is otherwise kept), so they make it
     * into the menu the model is shown and the rule-based pool. Nothing is removed.
     *
     * @param  Collection<int, Food>  $foods
     * @param  array{economical?: bool, easy_prep?: bool}  $softTags
     * @return Collection<int, Food>
     */
    private function tagPreferred(Collection $foods, array $softTags): Collection
    {
        if (! ($softTags['economical'] ?? false) && ! ($softTags['easy_prep'] ?? false)) {
            return $foods;
        }

        return $foods->values()->sortBy(fn (Food $food, int $i) => [$this->tagScore($food, $softTags), $i])->values();
    }

    /** A food's cost tier in the drafting nutritionist's country (Food::resolveCostTier, the one rule). */
    private function costOf(Food $food): ?string
    {
        return Food::resolveCostTier($this->costCountry, $this->regionalTiers[$food->id] ?? null, $food->getAttribute('cost_tier'));
    }

    /** 0 = matches every ticked preference ... 2 = matches none; always 0 with nothing ticked. */
    private function tagScore(Food $food, array $softTags): int
    {
        return (($softTags['economical'] ?? false) && $this->costOf($food) !== 'economical' ? 1 : 0)
            + (($softTags['easy_prep'] ?? false) && ! in_array($food->prep_level, ['no_cook', 'easy'], true) ? 1 : 0);
    }

    /**
     * Which safe foods the model is shown, best first: the curated Arabic
     * dishes, then foods the admin or a nutritionist added, then catalog foods
     * that have an Arabic name (the common ingredients), then the rest. The
     * allergy pre-filter has already run on $safeFoods.
     *
     * @return Collection<int, Food>
     */
    private function menuFor(Collection $safeFoods, array $softTags = []): Collection
    {
        return $safeFoods
            ->sortBy(fn (Food $food) => [
                // Phase 4, B6: a ticked cost / preparation preference decides who makes the menu at all.
                $this->tagScore($food, $softTags),
                match (true) {
                    $food->seed_key !== null => 0,
                    $food->source !== 'usda' => 1,
                    filled($food->name_ar) => 2,
                    default => 3,
                },
                $food->id,
            ])
            ->take(self::MAX_CANDIDATE_FOODS)
            ->values();
    }

    /** @param  array{likes?: list<string>, dislikes?: list<string>}  $foodNames  Arabic names only */
    private function userPrompt(Collection $candidateFoods, int $dailyCalories, HealthProfile $profile, ?string $goal, array $groups = [], array $foodNames = [], array $softTags = []): string
    {
        // Phase 4, B6: a tag column only for a preference that was ticked, so nothing else new reaches the model.
        $withCost = (bool) ($softTags['economical'] ?? false);
        $withPrep = (bool) ($softTags['easy_prep'] ?? false);

        // Compact rows, one array per food, in the order of food_columns.
        $foods = $candidateFoods->map(fn (Food $food) => array_merge([
            $food->id,
            $food->name_en ?? $food->name_ar,
            round((float) $food->calories_per_100g),
            round((float) $food->protein_g_per_100g, 1),
            round((float) $food->carbs_g_per_100g, 1),
            round((float) $food->fat_g_per_100g, 1),
        ], $withCost ? [$this->costOf($food)] : [], $withPrep ? [$food->prep_level] : []))->values()->all();

        $mealTargets = [];
        foreach (self::MEAL_CALORIE_SHARE as $mealName => $share) {
            $mealTargets[$mealName] = round($dailyCalories * $share);
        }

        // Build a rich client context so the LLM can reason like a
        // professional nutritionist — not just calorie-fit.
        $clientProfile = array_filter([
            'goal' => $goal,
            'target_weight_kg' => $profile->subscriber?->patientGoal?->target_weight_kg !== null ? (float) $profile->subscriber->patientGoal->target_weight_kg : null,
            'training_days_per_week' => $profile->subscriber?->patientGoal?->training_days_per_week,
            'training_type' => $profile->subscriber?->patientGoal?->training_type,
            'gender' => $profile->gender,
            'age' => $profile->age,
            'weight_kg' => $profile->weight_kg !== null ? (float) $profile->weight_kg : null,
            'height_cm' => $profile->height_cm !== null ? (float) $profile->height_cm : null,
            'activity_level' => $profile->activity_level,
            'health_conditions' => $profile->health_conditions,
            'medications' => $profile->medications,
            'allergies' => $profile->allergies,
            'food_preferences' => $profile->food_preferences,
            // Phase 2, B5: soft preferences, by NAME only (no ids, nothing else new).
            'liked_foods' => $foodNames['likes'] ?? [],
            'disliked_foods' => $foodNames['dislikes'] ?? [],
            'surgery_history' => $profile->surgery_history,
            'lab_notes' => $profile->lab_notes,
            'nutritionist_notes' => $profile->nutritionist_notes,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $payload = [
            'daily_calorie_target' => $dailyCalories,
            'meal_calorie_targets' => $mealTargets,
            'food_columns' => array_merge(['id', 'name', 'kcal_per_100g', 'protein_g_per_100g', 'carbs_g_per_100g', 'fat_g_per_100g'], $withCost ? ['cost_tier'] : [], $withPrep ? ['prep_level'] : []),
            'available_foods' => $foods,
        ];

        if ($withCost || $withPrep) {
            // SOFT: the system prompt says so; null in a tag column means "not reviewed".
            $payload = ['soft_constraints' => array_filter([
                'prefer_economical_foods' => $withCost ?: null,
                'prefer_no_cook_or_easy_prep_foods' => $withPrep ?: null,
            ])] + $payload;
        }

        if ($clientProfile !== []) {
            $payload = ['client_profile' => $clientProfile] + $payload;
        }

        // Stated again on its own, as a hard rule rather than context (system prompt rule 6).
        $avoid = array_values(array_unique(array_merge($profile->allergies ?? [], array_map(fn ($g) => str_replace('_', ' / ', $g), $groups))));
        if ($avoid !== []) {
            $payload = ['must_avoid_allergies' => $avoid] + $payload;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
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
            if (! is_array($rawItems) || $rawItems === [] || count($rawItems) > self::MAX_ITEMS_PER_MEAL) {
                return null;
            }

            $items = [];
            $inThisMeal = [];
            foreach ($rawItems as $rawItem) {
                $item = $this->validateLlmItem($rawItem, $validFoodIds);
                if ($item === null || in_array($item['food_id'], $plannedFoodIds, true) || in_array($item['food_id'], $inThisMeal, true)) {
                    return null;
                }

                $alternatives = [];
                foreach (($rawItem['alternatives'] ?? []) as $rawAlt) {
                    if (count($alternatives) >= 2) {
                        break;
                    }
                    $alt = $this->validateLlmItem($rawAlt, $validFoodIds);
                    if ($alt === null) {
                        return null;
                    }
                    // A duplicate or self-alternative is dropped, not a reason to discard the plan.
                    if ($alt['food_id'] === $item['food_id'] || in_array($alt['food_id'], array_column($alternatives, 'food_id'), true)) {
                        continue;
                    }
                    $alternatives[] = $alt;
                }

                $plannedFoodIds[] = $item['food_id'];
                $inThisMeal[] = $item['food_id'];
                $items[] = ['food_id' => $item['food_id'], 'quantity_grams' => $item['quantity_grams'], 'alternatives' => $alternatives];
            }
            $seenMealNames[] = $name;
            $meals[] = ['name' => $name, 'items' => $items];
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

        if (! is_numeric($quantity) || $quantity < 10 || $quantity > 500) {
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
