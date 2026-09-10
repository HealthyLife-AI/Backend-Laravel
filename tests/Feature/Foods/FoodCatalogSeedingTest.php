<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\User;
use Database\Seeders\ArabicFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin food catalog has to survive being seeded more than once:
 * Taqat runs `db:seed --force` as part of a deploy, so every redeploy
 * re-runs `ArabicFoodSeeder` against a database that already has its
 * dishes. It didn't survive it — `upsert(uniqueBy: ['name_en'])` had no
 * UNIQUE index behind it, MySQL dropped the conflict clause, and
 * production ended up with two of all 58 dishes. These cover both halves
 * of the repair: the seeder no longer duplicates, and the migration
 * cleans up the rows it already duplicated.
 */
class FoodCatalogSeedingTest extends TestCase
{
    use RefreshDatabase;

    private function runDedupeMigration(): void
    {
        $migration = require database_path('migrations/2026_09_10_100000_dedupe_admin_seeded_foods.php');
        $migration->up();
    }

    private function makeAdminFood(string $nameEn, float $calories = 100): Food
    {
        return Food::create([
            'source' => 'admin',
            'status' => 'approved',
            'name_en' => $nameEn,
            'name_ar' => 'اسم عربي',
            'calories_per_100g' => $calories,
            'protein_g_per_100g' => 5,
            'carbs_g_per_100g' => 10,
            'fat_g_per_100g' => 2,
            'fiber_g_per_100g' => 1,
        ]);
    }

    /** A plan item pointing at $food, so the FK to `foods` is exercised. */
    private function makeMealItemFor(Food $food): int
    {
        // Plain user, no role: `meal_plans.created_by` only needs a valid
        // user row here — this fixture is about the foreign key to
        // `foods`, not about who may edit a plan.
        $creator = User::factory()->create();

        $planId = DB::table('meal_plans')->insertGetId([
            'created_by' => $creator->id,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId,
            'name' => 'lunch',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('meal_items')->insertGetId([
            'meal_id' => $mealId,
            'food_id' => $food->id,
            'quantity_grams' => 200,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_seeding_the_arabic_catalog_twice_does_not_duplicate_it(): void
    {
        $this->seed(ArabicFoodSeeder::class);
        $afterFirstRun = Food::where('source', 'admin')->count();

        $this->seed(ArabicFoodSeeder::class);

        $this->assertSame($afterFirstRun, Food::where('source', 'admin')->count());
        $this->assertSame(1, Food::where('name_en', 'Basmati Rice, Cooked')->count());
    }

    public function test_reseeding_corrects_a_changed_value_in_place(): void
    {
        $this->seed(ArabicFoodSeeder::class);
        Food::where('name_en', 'Hummus')->update(['calories_per_100g' => 1]);

        $this->seed(ArabicFoodSeeder::class);

        $hummus = Food::where('name_en', 'Hummus')->sole();
        $this->assertSame('166.0', $hummus->calories_per_100g);
    }

    public function test_the_dedupe_migration_merges_duplicate_admin_dishes(): void
    {
        $canonical = $this->makeAdminFood('Chicken Kabsa');
        $duplicate = $this->makeAdminFood('Chicken Kabsa');

        $this->runDedupeMigration();

        $this->assertDatabaseHas('foods', ['id' => $canonical->id]);
        $this->assertDatabaseMissing('foods', ['id' => $duplicate->id]);
    }

    public function test_the_dedupe_migration_repoints_plan_items_off_the_duplicate(): void
    {
        $canonical = $this->makeAdminFood('Mujaddara (Rice & Lentils)');
        $duplicate = $this->makeAdminFood('Mujaddara (Rice & Lentils)');
        // The FK on meal_items.food_id restricts deletes, so a plan item
        // still pointing at the duplicate is exactly what would make this
        // migration blow up mid-deploy if it deleted before repointing.
        $itemId = $this->makeMealItemFor($duplicate);

        $this->runDedupeMigration();

        $this->assertDatabaseHas('meal_items', ['id' => $itemId, 'food_id' => $canonical->id]);
        $this->assertDatabaseMissing('foods', ['id' => $duplicate->id]);
    }

    public function test_the_dedupe_migration_leaves_nutritionist_submissions_alone(): void
    {
        // BR-5: two nutritionists may each submit their own "Hummus" with
        // their own macros. Same name, different foods — not duplicates.
        $first = Food::factory()->create(['source' => 'nutritionist', 'status' => 'approved', 'name_en' => 'Hummus']);
        $second = Food::factory()->create(['source' => 'nutritionist', 'status' => 'approved', 'name_en' => 'Hummus']);

        $this->runDedupeMigration();

        $this->assertDatabaseHas('foods', ['id' => $first->id]);
        $this->assertDatabaseHas('foods', ['id' => $second->id]);
    }

    public function test_the_dedupe_migration_is_a_no_op_on_a_clean_catalog(): void
    {
        $this->seed(ArabicFoodSeeder::class);
        $before = Food::count();

        $this->runDedupeMigration();

        $this->assertSame($before, Food::count());
    }
}
