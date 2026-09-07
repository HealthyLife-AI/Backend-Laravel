<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source',
    'status',
    'submitted_by',
    'name_en',
    'name_ar',
    'usda_fdc_id',
    'calories_per_100g',
    'protein_g_per_100g',
    'carbs_g_per_100g',
    'fat_g_per_100g',
    'fiber_g_per_100g',
])]
class Food extends Model
{
    use HasFactory;

    // Doctrine's inflector treats "food" as an uncountable mass noun (like
    // "sheep") and would otherwise guess the table name as `food`, not
    // `foods` — override explicitly rather than rely on the convention.
    protected $table = 'foods';

    protected function casts(): array
    {
        return [
            'calories_per_100g' => 'decimal:1',
            'protein_g_per_100g' => 'decimal:1',
            'carbs_g_per_100g' => 'decimal:1',
            'fat_g_per_100g' => 'decimal:1',
            'fiber_g_per_100g' => 'decimal:1',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** Only what's publicly searchable — BR-5. */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /**
     * Prefix match on either language (see migration comment for why not
     * FULLTEXT). `$term` should already be trimmed by the caller.
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term) {
            $q->where('name_en', 'like', "{$term}%")
                ->orWhere('name_ar', 'like', "{$term}%");
        });
    }
}
