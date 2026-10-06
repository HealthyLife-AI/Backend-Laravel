<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A weekly window (weekday 0 = Sunday) when the nutritionist takes appointments. */
#[Fillable(['nutritionist_id', 'weekday', 'start_time', 'end_time'])]
class NutritionistAvailability extends Model
{
    protected $table = 'nutritionist_availability';
}
