<?php

namespace App\Http\Resources;

use App\Models\MealPlan;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin MealPlan
 */
class MealPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscriber_id' => $this->subscriber_id,
            'is_template' => $this->is_template,
            'is_ai_draft' => $this->is_ai_draft,
            // Which path produced an AI draft (true = the rule-based calorie fit,
            // not the model). For the nutritionist only: the patient's own plan
            // (GET /me/meal-plan) never carries it.
            $this->mergeUnless(Auth::user()?->hasRole('client'), fn () => ['is_ai_fallback' => (bool) $this->is_ai_fallback]),
            /*
             * Item C, the nutritionist's own decision about this plan —
             * the approved target, the formula's figure at activation,
             * and her written reasoning. The patient follows the meals;
             * the reasoning behind them is a clinical note between her
             * and the file, and `plan_summary` is sensitive health data
             * that never reaches a client response (nor a log or Sentry).
             */
            $this->mergeUnless(Auth::user()?->hasRole('client'), fn () => [
                'approved_calories' => $this->approved_calories !== null ? (int) $this->approved_calories : null,
                'suggested_calories' => $this->suggested_calories !== null ? (int) $this->suggested_calories : null,
                'plan_summary' => $this->plan_summary,
                'summary_updated_at' => $this->summary_updated_at?->toIso8601String(),
            ]),
            // Only a template is ever given one deliberately — see the
            // migration. Null on a hand-built client plan, which has one
            // audience and doesn't need to be told apart from another.
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'status' => $this->status,
            // When the client could first actually follow this plan —
            // not when it was drafted (`created_at`). Null on a draft,
            // and on plans that predate the column.
            'activated_at' => $this->activated_at?->toIso8601String(),
            'meals' => MealResource::collection($this->whenLoaded('meals')),
            // FR-14: the "live daily summary" — per-day totals, shown
            // alongside the plan itself rather than a separate endpoint,
            // since the plan designer needs both in the same view.
            'summary_by_day' => $this->whenLoaded(
                'meals',
                fn () => app(MealPlanCalculatorService::class)->planSummaryByDay($this->resource)
            ),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
