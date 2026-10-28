<?php

namespace App\Models;

use App\Support\ArabicText;
use App\Support\RegionalCountry;
use App\Support\FoodTagger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'cost_tier',
    'prep_level',
])]
class Food extends Model
{
    use HasFactory;

    // Doctrine's inflector treats "food" as an uncountable mass noun (like
    // "sheep") and would otherwise guess the table name as `food`, not
    // `foods` — override explicitly rather than rely on the convention.
    protected $table = 'foods';

    /**
     * The regional cost rows for the CURRENT request's country, loaded with every food in
     * one extra query per batch (never per food): see RegionalCountry and costTier().
     *
     * @var list<string>
     */
    protected $with = ['regionalCosts'];

    /** Phase 4, B: how costly a food is. Null = not reviewed (shows nothing). */
    public const COST_TIERS = ['economical', 'standard', 'premium'];

    /** Phase 4, B: how much preparation it needs. Null = not reviewed. */
    public const PREP_LEVELS = ['no_cook', 'easy', 'normal'];

    /** Carbohydrate from which a missing glycemic index is worth chasing (g per 100 g, fibre taken off). */
    public const GI_RELEVANT_AVAILABLE_CARBS = 5;

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
            'sodium_mg_per_100g' => 'decimal:1',
            'nutrition_reviewed_at' => 'datetime',
            'tags_reviewed_at' => 'datetime',
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

    /** This food's cost row for the current request's country (at most one; eager-loaded with every food). */
    public function regionalCosts(): HasMany
    {
        return $this->hasMany(FoodRegionalCost::class)->where('country', RegionalCountry::current());
    }

    /** Every country's cost row (admin screens). */
    public function allRegionalCosts(): HasMany
    {
        return $this->hasMany(FoodRegionalCost::class)->orderBy('country');
    }

    /**
     * The ONE rule for a food's cost tier in a country:
     *  - a regional row for that country, if there is one;
     *  - else foods.cost_tier, but only for the default country (PS, whose value it is);
     *  - else null (unknown). Another country never inherits the PS value.
     */
    public static function resolveCostTier(string $country, ?string $regionalTier, ?string $baseTier): ?string
    {
        return $regionalTier ?? ($country === RegionalCountry::default() ? $baseTier : null);
    }

    public function costTierFor(string $country): ?string
    {
        // The eager-loaded row is for the current request's country; any other country is asked of the database.
        $regional = $this->relationLoaded('regionalCosts') && $country === RegionalCountry::current()
            ? $this->regionalCosts->first()?->cost_tier
            : FoodRegionalCost::query()->where('food_id', $this->id)->where('country', $country)->value('cost_tier');

        return self::resolveCostTier($country, $regional, $this->getAttribute('cost_tier'));
    }

    /** The cost tier as the current request's viewer sees it (their nutritionist's country). */
    public function costTier(): ?string
    {
        return $this->costTierFor(RegionalCountry::current());
    }

    /**
     * The same rule as costTierFor(), as a query, for foods whose tier in `$country` is one of `$tiers`.
     *
     * @param  list<string>  $tiers
     */
    public function scopeCostTierIn(Builder $query, string $country, array $tiers): void
    {
        $regional = fn ($q) => $q->selectRaw('1')->from('food_regional_costs as r')
            ->whereColumn('r.food_id', 'foods.id')->where('r.country', $country);

        $query->where(function (Builder $w) use ($regional, $tiers, $country) {
            $w->whereExists(fn ($e) => $regional($e)->whereIn('r.cost_tier', $tiers));

            if ($country === RegionalCountry::default()) {
                $w->orWhere(fn (Builder $p) => $p->whereIn('foods.cost_tier', $tiers)->whereNotExists(fn ($e) => $regional($e)));
            }
        });
    }

    /** Foods with no known cost tier in `$country` (the complement of costTierIn() over every tier). */
    public function scopeCostTierUnknown(Builder $query, string $country): void
    {
        $query->whereNotExists(fn ($e) => $e->selectRaw('1')->from('food_regional_costs as r')->whereColumn('r.food_id', 'foods.id')->where('r.country', $country));

        if ($country === RegionalCountry::default()) {
            $query->whereNull('foods.cost_tier');
        }
    }

    /** The nutritionist or admin who last set the glycemic index / sodium. */
    public function nutritionReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nutrition_reviewed_by');
    }

    /**
     * What a reviewer still has to fill in, in a fixed order: glycemic_index (only for a
     * food with at least 5 g available carbohydrate per 100 g: meat and oils have no index),
     * sodium, cost_tier, prep_level.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $availableCarbs = (float) $this->carbs_g_per_100g - (float) ($this->fiber_g_per_100g ?? 0);

        return array_values(array_filter([
            $this->glycemic_index === null && $availableCarbs >= self::GI_RELEVANT_AVAILABLE_CARBS ? 'glycemic_index' : null,
            $this->sodium_mg_per_100g === null ? 'sodium' : null,
            $this->costTier() === null ? 'cost_tier' : null,
            $this->prep_level === null ? 'prep_level' : null,
        ]));
    }

    /** The same rule as missingFields(), as a query: glycemic_index | sodium | tags. */
    public function scopeMissing(Builder $query, string $kind, ?string $country = null): void
    {
        $country ??= RegionalCountry::default();

        match ($kind) {
            'glycemic_index' => $query->whereNull('glycemic_index')->whereRaw('(carbs_g_per_100g - COALESCE(fiber_g_per_100g, 0)) >= ?', [self::GI_RELEVANT_AVAILABLE_CARBS]),
            'sodium' => $query->whereNull('sodium_mg_per_100g'),
            'tags' => $query->where(fn (Builder $q) => $q->costTierUnknown($country)->orWhereNull('foods.prep_level')),
            default => null,
        };
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
