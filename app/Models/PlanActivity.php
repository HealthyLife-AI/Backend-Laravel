<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 1, A: a physical-activity prescription that is part of a meal plan
 * ("30 minutes of light walking, once a day, 5 days a week"). Text and
 * numbers only: the app calculates no calorie burn from it.
 */
#[Fillable(['meal_plan_id', 'title', 'description', 'duration_minutes', 'times_per_day', 'days_per_week', 'sort_order'])]
class PlanActivity extends Model
{
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'times_per_day' => 'integer',
            'days_per_week' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }
}
