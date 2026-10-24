<?php

namespace App\Http\Resources;

use App\Models\PlanActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 1, A: one activity of a plan. Written for the patient, so the
 * client role receives it too (see MealPlanResource).
 *
 * @mixin PlanActivity
 */
class PlanActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'times_per_day' => $this->times_per_day,
            'days_per_week' => $this->days_per_week,
            'sort_order' => $this->sort_order,
        ];
    }
}
