<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 2, B: a food the patient likes or dislikes (a soft preference, never an allergy). */
#[Fillable(['subscriber_id', 'food_id', 'kind', 'source'])]
class PatientFoodPreference extends Model
{
    public const KINDS = ['like', 'dislike'];

    public const SOURCE_PATIENT = 'patient';

    public const SOURCE_NUTRITIONIST = 'nutritionist';

    /** Most foods in each list a single PUT may carry. */
    public const MAX_PER_LIST = 30;

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
