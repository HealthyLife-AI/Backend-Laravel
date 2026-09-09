<?php

namespace App\Http\Resources;

use App\Models\MealPlan;
use App\Services\Nutrition\MealPlanCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'start_date' => $this->start_date?->toDateString(),
            'status' => $this->status,
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
