<?php

namespace App\Services\MealPlans;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S3-01/S3-03: builds and rebuilds the meals/items under a plan, and the
 * template save/apply and activate actions that move a plan between
 * `draft` -> `active` -> `archived` (BR-6/BR-10 — see `MealPlan`'s and
 * the `meal_plans` migration's docblocks for why `activate()` is the
 * only door into `active`).
 */
class MealPlanService
{
    /**
     * @param  array<string, mixed>  $data  Validated `StoreMealPlanRequest` payload.
     */
    public function create(?Subscriber $subscriber, array $data, User $creator, bool $isTemplate = false, bool $isAiDraft = false): MealPlan
    {
        return DB::transaction(function () use ($subscriber, $data, $creator, $isTemplate, $isAiDraft) {
            $plan = MealPlan::create([
                'subscriber_id' => $subscriber?->id,
                'created_by' => $creator->id,
                'is_template' => $isTemplate,
                'is_ai_draft' => $isAiDraft,
                'start_date' => $data['start_date'] ?? null,
                'status' => 'draft',
            ]);

            $this->replaceMeals($plan, $data['meals']);

            return $plan;
        });
    }

    /**
     * The caller still submits the plan as one whole form (PRD F-4), but
     * this RECONCILES it against what is already stored instead of
     * dropping every row and rebuilding.
     *
     * That distinction is not a performance detail, it is a correctness
     * one. `meal_logs.meal_item_id` is `nullOnDelete`, and adherence
     * (S4-03) is a live count over that column across any window. A
     * delete-and-rebuild therefore nulled the reference on EVERY
     * historical log attached to the plan on EVERY edit — so a
     * nutritionist who adjusted one meal's portion silently rewrote the
     * client's past adherence downward, turning meals that were on-plan
     * when they were eaten into off-plan ones. Nothing in the UI hinted
     * at it; the percentage simply dropped, and `classify()` could flip
     * the client to `declining` on the strength of the nutritionist's
     * own edit.
     *
     * Identity is (meal slot, food) — a meal is the same meal if it is
     * the same `name` on the same `day_index`, and an item is the same
     * item if it is the same food in that slot:
     *
     * - Portion adjusted (250g -> 300g of the same food): clinically the
     *   same prescription, tuned. The row is UPDATED, its id survives,
     *   and past logs stay attributed.
     * - Food swapped (rice -> quinoa): genuinely a different item. The
     *   old row is deleted and its logs become unattributed, which is
     *   honest — the thing they were measured against no longer exists.
     * - Meal or item removed entirely: same as above, by intent.
     *
     * The `nullOnDelete` behaviour the `meal_logs` migration describes is
     * unchanged and still correct; it now only fires for items that were
     * actually removed, which is what that migration's docblock always
     * meant.
     *
     * @param  array<int, array<string, mixed>>  $mealsData
     */
    public function replaceMeals(MealPlan $plan, array $mealsData): void
    {
        DB::transaction(function () use ($plan, $mealsData) {
            $existingMeals = $plan->meals()->with('items')->get()
                ->keyBy(fn (Meal $meal) => $this->mealKey($meal->name, $meal->day_index));

            $keptMealIds = [];

            foreach ($mealsData as $mealIndex => $mealData) {
                $key = $this->mealKey($mealData['name'], $mealData['day_index'] ?? null);
                $existing = $existingMeals->get($key);

                if ($existing instanceof Meal) {
                    $existing->update(['sort_order' => $mealData['sort_order'] ?? $mealIndex]);
                    $meal = $existing;
                } else {
                    $meal = $plan->meals()->create([
                        'name' => $mealData['name'],
                        'day_index' => $mealData['day_index'] ?? null,
                        'sort_order' => $mealData['sort_order'] ?? $mealIndex,
                    ]);
                }

                $keptMealIds[] = $meal->id;
                $this->reconcileItems($meal, $existing?->items ?? collect(), $mealData['items']);
            }

            // Whatever the submitted form no longer contains.
            $plan->meals()->whereNotIn('id', $keptMealIds ?: [0])->delete();
        });
    }

    /** A meal is the same meal if it is the same slot on the same day. */
    private function mealKey(string $name, ?int $dayIndex): string
    {
        return $name.'@'.($dayIndex ?? 'daily');
    }

    /**
     * Planned items are matched by food within the meal; alternatives are
     * matched by food within their parent item. An alternative promoted
     * to a planned item (or vice versa) is intentionally NOT carried
     * over — its role changed, and BR-9 counts the two differently.
     *
     * @param  Collection<int, MealItem>  $existingItems
     * @param  array<int, array<string, mixed>>  $itemsData
     */
    private function reconcileItems(Meal $meal, $existingItems, array $itemsData): void
    {
        $existingPlanned = $existingItems->whereNull('parent_item_id')->keyBy('food_id');
        $keptItemIds = [];

        foreach ($itemsData as $itemIndex => $itemData) {
            $existing = $existingPlanned->get($itemData['food_id']);

            if ($existing instanceof MealItem) {
                $existing->update([
                    'quantity_grams' => $itemData['quantity_grams'],
                    'sort_order' => $itemIndex,
                ]);
                $plannedItem = $existing;
            } else {
                $plannedItem = $meal->items()->create([
                    'food_id' => $itemData['food_id'],
                    'quantity_grams' => $itemData['quantity_grams'],
                    'sort_order' => $itemIndex,
                ]);
            }

            $keptItemIds[] = $plannedItem->id;

            $existingAlternatives = $existingItems
                ->where('parent_item_id', $plannedItem->id)
                ->keyBy('food_id');

            foreach ($itemData['alternatives'] ?? [] as $altIndex => $altData) {
                $existingAlt = $existingAlternatives->get($altData['food_id']);

                if ($existingAlt instanceof MealItem) {
                    $existingAlt->update([
                        'quantity_grams' => $altData['quantity_grams'],
                        'sort_order' => $altIndex,
                    ]);
                    $keptItemIds[] = $existingAlt->id;

                    continue;
                }

                $keptItemIds[] = $meal->items()->create([
                    'food_id' => $altData['food_id'],
                    'quantity_grams' => $altData['quantity_grams'],
                    'parent_item_id' => $plannedItem->id,
                    'sort_order' => $altIndex,
                ])->id;
            }
        }

        $meal->items()->whereNotIn('id', $keptItemIds ?: [0])->delete();
    }

    /**
     * S3-03: clone a plan's meals/items into a new template the
     * nutritionist owns directly (no subscriber) — a snapshot, not a
     * live link, so editing the original client's plan later never
     * changes the template.
     */
    public function saveAsTemplate(MealPlan $plan, User $creator): MealPlan
    {
        return DB::transaction(function () use ($plan, $creator) {
            $template = MealPlan::create([
                'subscriber_id' => null,
                'created_by' => $creator->id,
                'is_template' => true,
                'is_ai_draft' => false,
                'start_date' => null,
                'status' => 'draft',
            ]);

            $this->cloneMealsInto($plan, $template);

            return $template;
        });
    }

    /**
     * S3-03: clone a template's meals/items into a brand-new draft plan
     * for a specific client — a starting point the nutritionist still
     * reviews and activates explicitly, same as any other draft.
     */
    public function applyTemplate(MealPlan $template, Subscriber $subscriber, User $creator): MealPlan
    {
        return DB::transaction(function () use ($template, $subscriber, $creator) {
            $plan = MealPlan::create([
                'subscriber_id' => $subscriber->id,
                'created_by' => $creator->id,
                'is_template' => false,
                'is_ai_draft' => false,
                'start_date' => null,
                'status' => 'draft',
            ]);

            $this->cloneMealsInto($template, $plan);

            return $plan;
        });
    }

    private function cloneMealsInto(MealPlan $source, MealPlan $target): void
    {
        foreach ($source->meals as $meal) {
            $newMeal = $target->meals()->create([
                'name' => $meal->name,
                'day_index' => $meal->day_index,
                'sort_order' => $meal->sort_order,
            ]);

            foreach ($meal->plannedItems as $item) {
                $newItem = $newMeal->items()->create([
                    'food_id' => $item->food_id,
                    'quantity_grams' => $item->quantity_grams,
                    'sort_order' => $item->sort_order,
                ]);

                foreach ($item->alternatives as $alt) {
                    $newMeal->items()->create([
                        'food_id' => $alt->food_id,
                        'quantity_grams' => $alt->quantity_grams,
                        'parent_item_id' => $newItem->id,
                        'sort_order' => $alt->sort_order,
                    ]);
                }
            }
        }
    }

    /**
     * The only path to `active` (BR-6/BR-10): archives whatever plan the
     * subscriber currently has active — a client has exactly one active
     * plan at a time — and clears `is_ai_draft`, since an activated draft
     * has, by definition, now been reviewed and approved.
     */
    public function activate(MealPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            MealPlan::where('subscriber_id', $plan->subscriber_id)
                ->where('status', 'active')
                ->where('id', '!=', $plan->id)
                ->update(['status' => 'archived']);

            // `activated_at` is when the client could first actually
            // follow this plan — distinct from created_at (when it was
            // drafted) and the only honest answer to "since when" for
            // both the progress chart and a case review.
            $plan->update(['status' => 'active', 'is_ai_draft' => false, 'activated_at' => now()]);
        });
    }
}
