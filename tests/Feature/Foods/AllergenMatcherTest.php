<?php

namespace Tests\Feature\Foods;

use App\Support\Allergens\AllergenMatcher;
use App\Support\FoodTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Audit A-01: allergen groups found in a food name from config/allergens.php,
 * word by word, in Arabic and English. No database.
 */
class AllergenMatcherTest extends TestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function names(): array
    {
        return [
            'Arabic peanut butter is peanuts, not milk' => ['زبدة الفول السوداني', ['peanuts']],
            'Arabic peanuts without the article' => ['فول سوداني محمص', ['peanuts']],
            'plain fava beans are not peanuts' => ['فول مدمس', []],
            'white (أبيض) is not egg' => ['خبز أبيض', ['wheat_gluten']],
            'white cheese is milk only' => ['جبنة بيضاء', ['milk_lactose']],
            'boiled eggs' => ['بيض مسلوق', ['egg']],
            'prefix بال is stripped' => ['شكشوكة بالبيض', ['egg']],
            'prefix وال is stripped' => ['خبز والجبنة', ['milk_lactose', 'wheat_gluten']],
            'coconut milk is neither milk nor tree nut' => ['حليب جوز الهند', []],
            'walnuts are tree nuts' => ['جوز عين الجمل', ['tree_nuts']],
            'grilled salmon' => ['سمك السلمون المشوي', ['fish']],
            'sesame ka\'ak' => ['كعك بالسمسم', ['sesame', 'wheat_gluten']],
            'lentils carry nothing' => ['شوربة عدس', []],
            'English peanut butter is peanuts only' => ['Peanut butter, smooth style', ['peanuts']],
            'USDA candy with plural peanuts' => ['Candies, NESTLE, GOOBERS Chocolate Covered Peanuts', ['peanuts']],
            'whole milk' => ['Milk, whole, 3.25% milkfat', ['milk_lactose']],
            'nutmeg is not a nut' => ['Spices, nutmeg, ground', []],
            'eggplant is not egg' => ['Eggplant, raw', []],
            'rice noodles are not wheat' => ['Rice noodles, cooked', []],
            'English plural ending' => ['Cookies, chocolate chip', ['wheat_gluten']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('names')]
    public function test_it_finds_the_allergen_groups_in_a_food_name(string $name, array $expected): void
    {
        $this->assertSame($expected, app(AllergenMatcher::class)->groupsInName($name));
    }

    public function test_the_tagger_finds_allergens_in_an_arabic_only_name(): void
    {
        // Before: FoodTagger's keywords were English only, so an Arabic-named
        // food with no English name was tagged with nothing.
        $tagger = app(FoodTagger::class);

        $this->assertSame(['peanuts'], $tagger->allergensByRules('زبدة الفول السوداني'));
        $this->assertSame(['milk_lactose'], $tagger->allergensByRules('لبنة بالزيت'));
        $this->assertSame(['egg', 'wheat_gluten'], $tagger->allergensByRules('سندويش بيض بالخبز'));
    }
}
