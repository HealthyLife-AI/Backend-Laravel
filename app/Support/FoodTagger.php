<?php

namespace App\Support;

use App\Models\Food;

/**
 * Allergen groups and the shopping-list section of a food.
 *
 * Hand-checked tags (the curated Arabic dishes and the Arabic-named catalog
 * items) come from database/data/food_tags_curated.csv, which is also the
 * file a nutritionist reviews. Every other food is tagged by rules: the
 * USDA SR Legacy food category (database/data/usda_fdc_categories.csv)
 * plus name keywords. The rules lean towards tagging: a wrong tag only
 * removes an option, a missing one could put an allergen in a plan.
 */
class FoodTagger
{
    public const GROUPS = ['tree_nuts', 'peanuts', 'milk_lactose', 'egg', 'wheat_gluten', 'sesame', 'fish', 'shellfish', 'soy'];

    public const SECTIONS = ['produce', 'meat_poultry_fish', 'dairy_eggs', 'grains_starches', 'legumes_nuts', 'oils_spices', 'other'];

    /** @var array<string, string>|null fdc_id => category */
    private static ?array $categories = null;

    /** @var array<string, array{allergens: list<string>, section: string}>|null "seed:key" / "fdc:id" => tags */
    private static ?array $curated = null;

    /** Name keywords per group (word-boundary, case-insensitive). */
    private const KEYWORDS = [
        'milk_lactose' => ['milk', 'cheese', 'cheeses', 'yogurt', 'yoghurt', 'butter', 'cream', 'whey', 'casein', 'lactose', 'buttermilk', 'custard', 'pudding', 'cheesecake', 'pizza', 'alfredo', 'au gratin', 'creamed', 'milkshake', 'latte', 'cappuccino', 'ghee', 'labneh', 'kunafa', 'muhallabia'],
        'egg' => ['egg', 'eggs', 'mayonnaise', 'custard', 'meringue', 'omelet', 'omelette', 'quiche', 'french toast', 'eggnog', 'shakshuka'],
        'wheat_gluten' => ['wheat', 'flour', 'bread', 'breads', 'bun', 'buns', 'pasta', 'spaghetti', 'macaroni', 'noodle', 'noodles', 'couscous', 'bulgur', 'semolina', 'durum', 'barley', 'rye', 'malt', 'cracker', 'crackers', 'cookie', 'cookies', 'cake', 'cakes', 'muffin', 'muffins', 'biscuit', 'biscuits', 'pie', 'pastry', 'pastries', 'croissant', 'croissants', 'pancake', 'pancakes', 'waffle', 'waffles', 'pretzel', 'pretzels', 'sandwich', 'burger', 'hamburger', 'breaded', 'batter', 'tortilla', 'tortillas', 'doughnut', 'doughnuts', 'bagel', 'bagels', 'crouton', 'croutons', 'stuffing', 'seitan', 'soy sauce', 'oats', 'oat', 'oatmeal', 'pita', 'manakish', 'freekeh', 'kibbeh'],
        'soy' => ['soy', 'soya', 'soybean', 'soybeans', 'tofu', 'edamame', 'miso', 'tempeh', 'natto'],
        'sesame' => ['sesame', 'tahini', 'halva', 'halavah', 'halawa', 'hummus', 'zaatar'],
        'peanuts' => ['peanut', 'peanuts'],
        'tree_nuts' => ['almond', 'almonds', 'walnut', 'walnuts', 'pecan', 'pecans', 'cashew', 'cashews', 'pistachio', 'pistachios', 'hazelnut', 'hazelnuts', 'filbert', 'filberts', 'macadamia', 'brazil nut', 'brazilnuts', 'pine nut', 'pine nuts', 'pinyon', 'chestnut', 'chestnuts', 'praline', 'marzipan', 'nougat', 'baklava', 'beechnut', 'hickorynuts'],
        'fish' => ['fish', 'salmon', 'tuna', 'cod', 'anchovy', 'anchovies', 'sardine', 'sardines', 'tilapia', 'trout', 'herring', 'mackerel', 'haddock', 'halibut', 'pollock', 'catfish', 'bass', 'snapper', 'swordfish', 'flounder', 'grouper', 'perch', 'pike', 'carp', 'whitefish', 'roe', 'caviar'],
        'shellfish' => ['shrimp', 'shrimps', 'crab', 'crabs', 'lobster', 'clam', 'clams', 'oyster', 'oysters', 'mussel', 'mussels', 'scallop', 'scallops', 'crayfish', 'squid', 'calamari', 'octopus', 'crustaceans', 'mollusks', 'abalone', 'whelk', 'prawn', 'prawns'],
    ];

    /** Phrases that look like a keyword but aren't that allergen. */
    private const NOT = [
        'milk_lactose' => ['peanut butter', 'apple butter', 'cocoa butter', 'butter beans', 'butterbur', 'cream of tartar', 'coconut milk', 'coconut cream', 'soymilk', 'soy milk', 'almond milk', 'rice milk', 'oat milk', 'nut butter', 'sesame butter', 'shea'],
        'egg' => ['eggplant', 'egg roll', 'egg rolls'],
        'wheat_gluten' => ['rice noodle', 'rice noodles', 'corn tortilla', 'corn tortillas', 'buckwheat', 'rice flour', 'corn flour', 'potato flour', 'soy flour', 'peanut flour', 'chickpea flour', 'tapioca', 'arrowroot', 'pie filling'],
        'tree_nuts' => ['water chestnut', 'water chestnuts', 'butternut', 'nutmeg', 'coconut'],
        'fish' => ['fish sauce substitute'],
    ];

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
        $groups = [];

        foreach (self::KEYWORDS as $group => $words) {
            $clean = $text;
            foreach (self::NOT[$group] ?? [] as $phrase) {
                $clean = str_replace($phrase, ' ', $clean);
            }
            foreach ($words as $word) {
                if (preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $clean)) {
                    $groups[] = $group;
                    break;
                }
            }
        }

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
