<?php

namespace App\Services\MealPlans;

use App\Models\Food;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * S3-06/S3-07 / F-5 (PRD, P1): "Suggest a starting plan." Rule-based, not
 * a real LLM call — no LLM provider is wired into this codebase (no API
 * key, no HTTP client for one), and F-5's own validation note says the
 * feature isn't even confirmed wanted yet; standing up real LLM
 * infrastructure for an unconfirmed P1 would be building a capability
 * this app can't actually back. The tracker (S3-06) explicitly allows
 * either approach.
 *
 * BR-6/BR-10 ("AI output is always an editable assistant artifact — never
 * auto-sent to a client"): this returns a plan with `status = 'draft'`
 * and `is_ai_draft = true`, and nothing in `MealPlanService` has a second
 * path to `active` — the nutritionist must call the same `activate()`
 * every hand-built plan goes through. That's enforced by there being no
 * other door, not by a rule this service has to remember to follow.
 *
 * Allergy handling is a best-effort case-insensitive substring exclusion
 * against each candidate food's name (English and Arabic) — a real
 * safety net, but explicitly not a certified allergen-matching system;
 * it can't catch an allergen an ingredient name doesn't mention (e.g. a
 * hidden dairy component). The nutritionist reviews every draft before
 * it can reach a client — this is a starting point, not a guarantee.
 */
class AiDraftPlanService
{
    /**
     * Standard clinical rule-of-thumb calorie split across the day's
     * four meal slots — not derived from this client's data, a common
     * default any plan has to start from somewhere.
     */
    private const MEAL_CALORIE_SHARE = [
        'breakfast' => 0.25,
        'snack' => 0.10,
        'lunch' => 0.35,
        'dinner' => 0.30,
    ];

    public function __construct(private readonly MealPlanService $plans) {}

    public function generateDraft(Subscriber $subscriber, User $creator): MealPlan
    {
        $profile = $subscriber->healthProfile;

        abort_if($profile === null, 422, 'This client needs a health profile (calorie needs, allergies) before an AI draft can be generated.');

        $dailyCalories = (int) $profile->daily_calorie_needs;
        $allergyTerms = array_map('mb_strtolower', $profile->allergies ?? []);

        $approvedFoods = Food::approved()->get();
        $safeFoods = $approvedFoods->reject(fn (Food $food) => $this->containsAllergen($food, $allergyTerms));

        abort_if($safeFoods->isEmpty(), 422, 'No approved foods are free of this client\'s allergies — add more foods to the database before drafting.');

        $meals = [];
        foreach (self::MEAL_CALORIE_SHARE as $mealName => $share) {
            $mealTarget = $dailyCalories * $share;
            $candidates = $this->rankByCalorieFit($safeFoods, $mealTarget)->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $planned = $candidates->first();
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

        return $this->plans->create($subscriber, ['meals' => $meals], $creator, isAiDraft: true);
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
     * @return Collection<int, array{food: Food, quantity_grams: float, fit: float}>
     */
    private function rankByCalorieFit(Collection $foods, float $mealTarget): Collection
    {
        return $foods
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
