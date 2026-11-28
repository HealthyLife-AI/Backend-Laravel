<?php

namespace App\Support;

use App\Models\Food;
use App\Support\Allergens\AllergenMatcher;

/**
 * Allergen groups and the shopping-list section of a food.
 *
 * Hand-checked tags (the curated Arabic dishes and the Arabic-named catalog
 * items) come from database/data/food_tags_curated.csv, which is also the
 * file a nutritionist reviews. Every other food is tagged by rules: the
 * USDA SR Legacy food category (database/data/usda_fdc_categories.csv)
 * plus the name keywords of config/allergens.php (Arabic and English). The
 * rules lean towards tagging: a wrong tag only removes an option, a missing
 * one could put an allergen in a plan.
 */
class FoodTagger
{
    public const GROUPS = ['tree_nuts', 'peanuts', 'milk_lactose', 'egg', 'wheat_gluten', 'sesame', 'fish', 'shellfish', 'soy'];

    public const SECTIONS = ['produce', 'meat_poultry_fish', 'dairy_eggs', 'grains_starches', 'legumes_nuts', 'oils_spices', 'other'];

    /** @var array<string, string>|null fdc_id => category */
    private static ?array $categories = null;

    /** @var array<string, array{allergens: list<string>, section: string}>|null "seed:key" / "fdc:id" => tags */
    private static ?array $curated = null;

    /** @return array{allergens: list<string>, section: string} */
    public function tagsFor(Food $food): array
    {
        if ($food->seed_key !== null && ($hit = $this->curated()["seed:{$food->seed_key}"] ?? null)) {
            return $hit;
        }

        if ($food->usda_fdc_id !== null && ($hit = $this->curated()["fdc:{$food->usda_fdc_id}"] ?? null)) {
            return $hit;
        }

        $category = $food->usda_fdc_id !== null ? ($this->categories()[(string) $food->usda_fdc_id] ?? null) : null;

        return [
            'allergens' => $this->allergensByRules((string) $food->name_en.' '.(string) $food->name_ar, $category),
            'section' => $food->source === 'usda' ? $this->sectionFor($category) : 'other',
        ];
    }

    /** @return list<string> */
    public function allergensByRules(string $name, ?string $category = null): array
    {
        $text = ' '.mb_strtolower($name).' ';
        // Name keywords (Arabic and English) live in config/allergens.php.
        $groups = app(AllergenMatcher::class)->groupsInName($name);

        $groups = array_merge($groups, match ($category) {
            'Dairy and Egg Products' => in_array('egg', $groups, true) ? [] : ['milk_lactose'],
            'Finfish and Shellfish Products' => in_array('shellfish', $groups, true) ? [] : ['fish'],
            'Baked Products', 'Breakfast Cereals' => preg_match('/\b(rice|corn|gluten-free)\b/', $text) ? [] : ['wheat_gluten'],
            'Cereal Grains and Pasta' => preg_match('/\b(rice|corn|cornmeal|millet|quinoa|amaranth|sorghum|teff|buckwheat|hominy|tapioca|arrowroot)\b/', $text) && ! preg_match('/\b(wheat|barley|rye|malt|semolina|couscous|bulgur)\b/', $text) ? [] : ['wheat_gluten'],
            default => [],
        });

        $groups = array_values(array_unique($groups));
        sort($groups);

        return $groups;
    }

    public function sectionFor(?string $category): string
    {
        return match ($category) {
            'Fruits and Fruit Juices', 'Vegetables and Vegetable Products' => 'produce',
            'Poultry Products', 'Beef Products', 'Pork Products', 'Lamb, Veal, and Game Products', 'Sausages and Luncheon Meats', 'Finfish and Shellfish Products' => 'meat_poultry_fish',
            'Dairy and Egg Products' => 'dairy_eggs',
            'Baked Products', 'Breakfast Cereals', 'Cereal Grains and Pasta' => 'grains_starches',
            'Legumes and Legume Products', 'Nut and Seed Products' => 'legumes_nuts',
            'Fats and Oils', 'Spices and Herbs' => 'oils_spices',
            default => 'other',
        };
    }

    /** Tag every food that hasn't been tagged yet (allergens null). */
    public function backfill(): int
    {
        $count = 0;

        Food::query()->whereNull('allergens')->orderBy('id')->chunkById(500, function ($foods) use (&$count) {
            foreach ($foods as $food) {
                $tags = $this->tagsFor($food);
                $food->forceFill(['allergens' => $tags['allergens'], 'shopping_section' => $tags['section']])->saveQuietly();
                $count++;
            }
        });

        return $count;
    }

    /** @return array<string, string> */
    private function categories(): array
    {
        if (self::$categories !== null) {
            return self::$categories;
        }

        self::$categories = [];
        $h = fopen(database_path('data/usda_fdc_categories.csv'), 'r');
        while (($row = fgetcsv($h, escape: '\\')) !== false) {
            if (! isset($row[0]) || ! ctype_digit($row[0])) {
                continue;
            }
            self::$categories[$row[0]] = $row[1] ?? '';
        }
        fclose($h);

        return self::$categories;
    }

    /** @return array<string, array{allergens: list<string>, section: string}> */
    private function curated(): array
    {
        if (self::$curated !== null) {
            return self::$curated;
        }

        self::$curated = [];
        $h = fopen(database_path('data/food_tags_curated.csv'), 'r');
        fgetcsv($h, escape: '\\');
        while (($row = fgetcsv($h, escape: '\\')) !== false) {
            [$type, $key, , , $allergens, $section] = $row;
            $groups = array_values(array_filter(explode(';', $allergens)));
            sort($groups);
            self::$curated["{$type}:{$key}"] = ['allergens' => $groups, 'section' => $section];
        }
        fclose($h);

        return self::$curated;
    }
}
