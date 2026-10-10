<?php

namespace App\Models;

use App\Support\ArabicText;
use App\Support\FoodTagger;
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
    'allergens',
    'shopping_section',
    'seed_key',
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

        // A new food is tagged (allergen groups, shopping section) unless the
        // caller set them; see FoodTagger.
        static::creating(function (Food $food) {
            if ($food->allergens === null) {
                $tags = app(FoodTagger::class)->tagsFor($food);
                $food->allergens = $tags['allergens'];
                $food->shopping_section ??= $tags['section'];
            }
            $food->shopping_section ??= 'other';
        });
    }

    protected function casts(): array
    {
        return [
            'allergens' => 'array',
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

    /** Shipped by a seeder (USDA catalog or a curated dish), which re-runs on every `db:seed`. */
    public function isSeeded(): bool
    {
        return $this->usda_fdc_id !== null || $this->seed_key !== null;
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
     * Ordered by relevance (B15): the term as a WHOLE word first ("salmon"
     * before "Salmonberries"), then names that START with it, then ones
     * where it starts a word, then anywhere; foods with an Arabic name (the
     * curated local list) ahead of English-only USDA rows; shorter (more
     * generic) names first.
     *
     * Arabic is compared in its normalized form (ArabicText) on both
     * sides, so "ارز" finds "أرز" and "بيضه" finds "بيضة"; a word that
     * ends in ه or ا also matches the other ending, because the same word
     * is written both ways ("تونة" / "تونا").
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $base = ArabicText::normalize($term);
        $terms = [$base];
        $last = mb_substr($base, -1);

        if (mb_strlen($base) > 2 && in_array($last, ['ه', 'ا'], true)) {
            $terms[] = mb_substr($base, 0, -1).($last === 'ه' ? 'ا' : 'ه');
        }

        $escaped = array_map(fn ($t) => addcslashes($t, '%_\\'), $terms);

        $query->where(function (Builder $q) use ($escaped) {
            foreach ($escaped as $t) {
                $q->orWhere('name_en', 'like', "%{$t}%")->orWhere('name_ar_normalized', 'like', "%{$t}%");
            }
        });

        // Tier 0: the term as a whole word (delimited by start/end, space or comma).
        $whole = [];
        $bindings = [];
        foreach ($escaped as $t) {
            foreach (["{$t}", "{$t} %", "{$t},%", "% {$t}", "% {$t} %", "% {$t},%"] as $pattern) {
                $whole[] = 'name_ar_normalized LIKE ? OR name_en LIKE ?';
                array_push($bindings, $pattern, $pattern);
            }
        }
        $starts = [];
        $startBindings = [];
        foreach ($escaped as $t) {
            $starts[] = 'name_ar_normalized LIKE ? OR name_en LIKE ?';
            array_push($startBindings, "{$t}%", "{$t}%");
        }
        $wordStarts = [];
        $wordBindings = [];
        foreach ($escaped as $t) {
            $wordStarts[] = 'name_ar_normalized LIKE ? OR name_en LIKE ?';
            array_push($wordBindings, "% {$t}%", "% {$t}%");
        }

        $query->orderByRaw(
            'CASE WHEN ('.implode(' OR ', $whole).') THEN 0 WHEN ('.implode(' OR ', $starts).') THEN 1 WHEN ('.implode(' OR ', $wordStarts).') THEN 2 ELSE 3 END',
            [...$bindings, ...$startBindings, ...$wordBindings],
        )->orderByRaw('CASE WHEN name_ar IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('LENGTH(COALESCE(name_ar, name_en))');
    }
}
