<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S4-00 / PRD §5.2: a nutritionist's own professional details.
 *
 * `plan_tier` is intentionally absent from the fillable list. It is
 * billing state, not profile content — a nutritionist editing their own
 * profile must not be able to promote themselves to a paid tier by
 * adding a field to the request body. It is set by the seeder default
 * today and will be written by the billing flow Post-MVP.
 */
#[Fillable([
    'user_id',
    'specialty',
    'clinic_name',
    'bio',
])]
class NutritionistProfile extends Model
{
    /**
     * Enforced here rather than as a database enum: the PRD lists these
     * under §8 "Open Decisions" and expects them to be revisited, so they
     * must be changeable without a migration (see the migration docblock).
     */
    public const TIERS = ['basic', 'professional'];

    /**
     * Mirrors the column default. The database default alone applies at
     * INSERT, so a freshly created model returns `plan_tier: null` until
     * it is re-read — the profile is created on first access, which made
     * the very first read of every profile report no tier at all. Both
     * are kept: this one so the object is correct in memory, the column
     * default so a direct insert or seeder can't bypass it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plan_tier' => 'basic',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
