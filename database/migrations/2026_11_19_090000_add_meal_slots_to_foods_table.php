<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * fix/ai-draft-meal-slots: which meal(s) a food is normally eaten at, so
 * `AiDraftPlanService` can reject an item or alternative placed in the
 * wrong one (seen in production: شاورما دجاج offered as the breakfast
 * alternative to eggs) instead of relying on prompt wording alone.
 *
 * `meal_slots` is a JSON array drawn from the plan's own meal names
 * (`breakfast`, `lunch`, `dinner`, `snack` — `snack` covers all of
 * morning/afternoon/snack, there's only one slot of that kind in a plan).
 * Three states, same convention as `cost_tier`/`prep_level`'s "null =
 * not reviewed":
 *   - NULL    = unrestricted — allowed in any slot (every USDA
 *               ingredient; most of the curated Arabic dishes).
 *   - `[]`    = never allowed in a drafted plan at all (raw/unsafe for a
 *               generated recommendation — currently just كبة نية, raw
 *               meat, a risk in pregnancy). AiDraftPlanService excludes
 *               these from `available_foods` entirely, for both the LLM
 *               and the rule-based path. Manual plans are unaffected —
 *               a nutritionist can still add it by hand.
 *   - `[...]` = allowed only in the listed slot(s).
 *
 * The slot list below is the result the user reviewed and returned
 * corrected on 2026-11-16 (see that conversation for the dish-by-dish
 * reasoning); it is applied here, once, by `seed_key` — the same stable
 * id `ArabicFoodSeeder` and the cost/prep backfill match on, so it
 * survives a nutritionist renaming or re-seeding a dish. Every USDA
 * ingredient (no `seed_key`) is left at the column default (NULL):
 * the review found no reliable per-ingredient slot data for the ~7,800-
 * row USDA catalog, and guessing one was explicitly out of scope.
 */
return new class extends Migration
{
    /** @var array<string, list<string>|null> seed_key => allowed slots; omitted entirely = untouched (stays NULL). */
    private const REVIEWED_SLOTS = [
        'fatteh-with-chickpeas' => ['breakfast', 'lunch'],
        'baba-ghanoush' => ['breakfast', 'lunch', 'dinner'],
        'fried-kibbeh' => ['lunch', 'dinner', 'snack'],
        'raw-kibbeh-kibbeh-nayyeh' => [],
        'ful-medames' => ['breakfast', 'lunch', 'dinner'],
        'boiled-egg-arabic-breakfast' => ['breakfast', 'dinner', 'snack'],
        'akkawi-white-cheese' => ['breakfast', 'dinner', 'snack'],
        'labneh' => ['breakfast', 'dinner', 'snack'],
        'zaatar-with-olive-oil' => ['breakfast', 'dinner', 'snack'],
        'zaatar-manakish' => ['breakfast', 'dinner', 'snack'],
        'shakshuka' => ['breakfast', 'dinner'],
        'musabaha' => ['breakfast', 'lunch', 'dinner'],
        'jareesh-cracked-wheat-porridge' => ['breakfast', 'lunch', 'dinner'],
        'halawet-el-jibn' => ['snack'],
        'kunafa' => ['snack'],
        'baklava' => ['snack'],
        'om-ali' => ['snack'],
        'muhallabia' => ['snack'],
        'cheese-rolls-riqaq' => ['breakfast', 'dinner', 'snack'],

        // Lunch/dinner mains and sides — never breakfast or (unless
        // listed above) snack.
        'white-rice-cooked-arabic-style' => ['lunch', 'dinner'],
        'basmati-rice-cooked' => ['lunch', 'dinner'],
        'mujaddara-rice-and-lentils' => ['lunch', 'dinner'],
        'chicken-kabsa' => ['lunch', 'dinner'],
        'beef-biryani' => ['lunch', 'dinner'],
        'musakhan-sumac-chicken' => ['lunch', 'dinner'],
        'tabbouleh' => ['lunch', 'dinner'],
        'fattoush' => ['lunch', 'dinner'],
        'stuffed-grape-leaves-vegetarian' => ['lunch', 'dinner'],
        'chicken-shawarma' => ['lunch', 'dinner'],
        'beef-shawarma' => ['lunch', 'dinner'],
        'moussaka-eggplant' => ['lunch', 'dinner'],
        'molokhia-with-chicken' => ['lunch', 'dinner'],
        'molokhia-plain' => ['lunch', 'dinner'],
        'maqluba-with-chicken' => ['lunch', 'dinner'],
        'mansaf' => ['lunch', 'dinner'],
        'shish-tawook' => ['lunch', 'dinner'],
        'grilled-kofta' => ['lunch', 'dinner'],
        'grilled-fish-general' => ['lunch', 'dinner'],
        'spinach-with-olive-oil' => ['lunch', 'dinner'],
        'green-beans-with-olive-oil' => ['lunch', 'dinner'],
        'white-bean-stew' => ['lunch', 'dinner'],
        'lentil-soup' => ['lunch', 'dinner'],
        'zucchini-mutabbal' => ['lunch', 'dinner'],
        'okra-stew' => ['lunch', 'dinner'],
        'kidney-bean-stew' => ['lunch', 'dinner'],
        'bulgur-with-tomato' => ['lunch', 'dinner'],
        'egyptian-koshari' => ['lunch', 'dinner'],
        'okra-with-meat' => ['lunch', 'dinner'],
        'saleeg-chicken-rice' => ['lunch', 'dinner'],
        'thareed' => ['lunch', 'dinner'],
        'greek-style-salad-arabic-version' => ['lunch', 'dinner'],

        // Reviewed and confirmed unrestricted (any slot) — listed
        // explicitly so the table states every dish the review covered,
        // not only the restricted ones.
        'hummus' => null,
        'falafel' => null,
        'egyptian-taameya' => null,
        'arabic-pita-bread' => null,
        'whole-wheat-bread-arabic-style' => null,
        'dates' => null,
        'plain-yogurt-arabic-style' => null,
    ];

    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->json('meal_slots')->nullable()->after('shopping_section');
        });

        foreach (self::REVIEWED_SLOTS as $seedKey => $slots) {
            DB::table('foods')
                ->where('seed_key', $seedKey)
                ->update(['meal_slots' => $slots === null ? null : json_encode($slots)]);
        }
    }

    public function down(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->dropColumn('meal_slots');
        });
    }
};
