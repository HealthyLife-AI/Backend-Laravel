<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BR-4: a food + quantity within a meal. `parent_item_id` null = the
 * planned item; set = one of that item's permitted alternatives.
 */
#[Fillable(['meal_id', 'food_id', 'parent_item_id', 'quantity_grams', 'sort_order'])]
class MealItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity_grams' => 'decimal:1',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MealItem::class, 'parent_item_id');
    }

    /** The alternatives permitted for this planned item. Empty for an alternative itself. */
    public function alternatives(): HasMany
    {
        return $this->hasMany(MealItem::class, 'parent_item_id')->orderBy('sort_order');
    }

    public function isPlanned(): bool
    {
        return $this->parent_item_id === null;
    }
}
