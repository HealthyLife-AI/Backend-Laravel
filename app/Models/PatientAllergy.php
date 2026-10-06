<?php

namespace App\Models;

use App\Support\AllergyGroups;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An approved allergy, intolerance or food to avoid: one of the nine groups, or "other" with text. */
#[Fillable(['subscriber_id', 'group', 'other_text', 'class', 'note'])]
class PatientAllergy extends Model
{
    public const GROUPS = ['tree_nuts', 'peanuts', 'milk_lactose', 'egg', 'wheat_gluten', 'sesame', 'fish', 'shellfish', 'soy', 'other'];

    public const CLASSES = ['confirmed_allergy', 'intolerance', 'avoid'];

    public function label(): string
    {
        return AllergyGroups::label($this->group, $this->other_text);
    }
}
