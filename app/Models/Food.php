<?php

namespace App\Models;

use App\Support\ArabicText;
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

    /** Keeps the search column in step with every Eloquent write of `name_ar`. */
    protected static function booted(): void
    {
        static::saving(function (Food $food) {
            $food->name_ar_normalized = ArabicText::normalize($food->name_ar);
        });
    }

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
     * Matches anywhere in either name, not just the start: with the full
     * USDA catalog loaded, "دجاج" must find "صدر دجاج مشوي" and "chicken"
     * must find "Soup, chicken noodle". Plain LIKE scans are fine at this
     * table's size (~8k rows). `$term` should already be trimmed.
     *
     * Ordered by relevance: names that START with the term, then ones
     * where it starts a word, then anywhere; foods with an Arabic name
     * (the curated local list) ahead of English-only USDA rows; shorter
     * (more generic) names first — "Rice, white, cooked" before a long
     * branded variant.
     *
     * Arabic is compared in its normalized form (ArabicText) on both
     * sides, so "ارز" finds "أرز" and "بيضه" finds "بيضة".
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $escaped = addcslashes(ArabicText::normalize($term), '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            $q->where('name_en', 'like', "%{$escaped}%")
                ->orWhere('name_ar_normalized', 'like', "%{$escaped}%");
        })->orderByRaw(
            'CASE WHEN name_ar_normalized LIKE ? OR name_en LIKE ? THEN 0 WHEN name_ar_normalized LIKE ? OR name_en LIKE ? THEN 1 ELSE 2 END',
            ["{$escaped}%", "{$escaped}%", "% {$escaped}%", "% {$escaped}%"],
        )->orderByRaw('CASE WHEN name_ar IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('LENGTH(COALESCE(name_ar, name_en))');
    }
}
