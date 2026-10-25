<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Phase 3, A: a patient opened a post. One row per pair. */
#[Fillable(['post_id', 'subscriber_id', 'read_at'])]
class NutritionistPostRead extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
