<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A food's cost tier in one country (see Food::costTierFor()). */
#[Fillable(['food_id', 'country', 'cost_tier', 'set_by'])]
class FoodRegionalCost extends Model
{
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
