<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** S3-01: a meal slot (breakfast/snack/lunch/dinner) within a plan. */
#[Fillable(['meal_plan_id', 'name', 'day_index', 'sort_order'])]
class Meal extends Model
{
    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    /** Every item in this meal — planned items and alternatives alike. */
    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)->orderBy('sort_order');
    }

    /** BR-4: items with no parent — what the plan actually calls for. */
    public function plannedItems(): HasMany
    {
        return $this->items()->whereNull('parent_item_id');
    }
}
