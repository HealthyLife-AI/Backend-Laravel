<?php

namespace App\Services\MealPlans;

use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
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
     * Full replace, not a diff against the existing meals/items — PRD
     * F-4 treats a plan as edited as one whole form, and a plan is small
     * enough (a handful of meals, a handful of items each) that
     * delete-and-rebuild is simpler and no slower than reconciling which
     * rows changed. `meal_items.parent_item_id` cascades on delete, so
     * dropping a meal's rows never leaves an orphaned alternative behind.
     *
     * @param  array<int, array<string, mixed>>  $mealsData
     */
    public function replaceMeals(MealPlan $plan, array $mealsData): void
    {
        DB::transaction(function () use ($plan, $mealsData) {
            $plan->meals()->delete();

            foreach ($mealsData as $mealIndex => $mealData) {
                $meal = $plan->meals()->create([
                    'name' => $mealData['name'],
                    'day_index' => $mealData['day_index'] ?? null,
                    'sort_order' => $mealData['sort_order'] ?? $mealIndex,
                ]);

                foreach ($mealData['items'] as $itemIndex => $itemData) {
                    $plannedItem = $meal->items()->create([
                        'food_id' => $itemData['food_id'],
                        'quantity_grams' => $itemData['quantity_grams'],
                        'sort_order' => $itemIndex,
                    ]);

                    foreach ($itemData['alternatives'] ?? [] as $altIndex => $altData) {
                        $meal->items()->create([
                            'food_id' => $altData['food_id'],
                            'quantity_grams' => $altData['quantity_grams'],
                            'parent_item_id' => $plannedItem->id,
                            'sort_order' => $altIndex,
                        ]);
                    }
                }
            }
        });
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

            $plan->update(['status' => 'active', 'is_ai_draft' => false]);
        });
    }
}
