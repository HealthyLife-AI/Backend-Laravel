<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use Database\Seeders\UsdaFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit A-01: 7,793 of 7,851 seeded foods had allergens NULL, because
 * UsdaFoodSeeder's Food::insert() skips the tagging in Food::booted().
 * `foods:tag-allergens` fixes existing rows: dry run by default, only adds.
 */
class TagAllergensTest extends TestCase
{
    use RefreshDatabase;

    /** A row the way the USDA seeder and importer write it: no model events, no tags. */
    private function untagged(string $name, ?string $nameAr = null): Food
    {
        Food::insert([[
            'source' => 'usda', 'status' => 'approved', 'name_en' => $name, 'name_ar' => $nameAr,
            'calories_per_100g' => 500, 'protein_g_per_100g' => 10, 'carbs_g_per_100g' => 50, 'fat_g_per_100g' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        return Food::query()->where('name_en', $name)->firstOrFail();
    }

    /** @param  list<string>  $allergens */
    private function tagged(string $name, array $allergens): Food
    {
        $food = Food::factory()->create(['name_en' => $name, 'name_ar' => null]);
        $food->forceFill(['allergens' => $allergens])->saveQuietly();

        return $food->refresh();
    }

    public function test_the_default_is_a_dry_run_that_writes_nothing(): void
    {
        $goobers = $this->untagged('Candies, NESTLE, GOOBERS Chocolate Covered Peanuts');

        $this->artisan('foods:tag-allergens')
            ->expectsOutputToContain('DRY RUN: nothing was written')
            ->expectsOutputToContain('Foods that would change: 1.')
            ->assertSuccessful();

        $this->assertNull($goobers->refresh()->allergens);
    }

    public function test_apply_tags_untagged_foods_in_english_and_arabic(): void
    {
        $goobers = $this->untagged('Candies, NESTLE, GOOBERS Chocolate Covered Peanuts');
        $arabic = $this->untagged('Peanut spread', 'زبدة الفول السوداني');

        $this->artisan('foods:tag-allergens', ['--apply' => true])
            ->expectsOutputToContain('APPLIED')
            ->assertSuccessful();

        $this->assertContains('peanuts', $goobers->refresh()->allergens);
        $this->assertSame(['peanuts'], $arabic->refresh()->allergens);
    }

    public function test_it_only_adds_tags_and_never_removes_one(): void
    {
        // A hand-checked tag the keywords would not find stays.
        $kept = $this->tagged('Bread, specialty blend', ['sesame']);
        // A tagged food whose name shows a group it lacks gains that group.
        $gains = $this->tagged('Yogurt, plain', ['egg']);

        $this->artisan('foods:tag-allergens', ['--apply' => true])->assertSuccessful();

        $this->assertSame(['sesame', 'wheat_gluten'], $kept->refresh()->allergens);
        $this->assertSame(['egg', 'milk_lactose'], $gains->refresh()->allergens);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->untagged('Candies, NESTLE, GOOBERS Chocolate Covered Peanuts');
        $this->tagged('Yogurt, plain', ['egg']);

        $this->artisan('foods:tag-allergens', ['--apply' => true])->assertSuccessful();

        $this->artisan('foods:tag-allergens', ['--apply' => true])
            ->expectsOutputToContain('Foods changed: 0.')
            ->assertSuccessful();
    }

    public function test_a_fresh_seed_leaves_no_usda_food_untagged(): void
    {
        $this->seed(UsdaFoodSeeder::class);

        $this->assertGreaterThan(7000, Food::query()->where('source', 'usda')->count());
        $this->assertSame(0, Food::query()->where('source', 'usda')->whereNull('allergens')->count());
        $this->assertContains('peanuts', Food::query()->where('name_en', 'like', '%GOOBERS%')->value('allergens') ?? []);
    }
}
