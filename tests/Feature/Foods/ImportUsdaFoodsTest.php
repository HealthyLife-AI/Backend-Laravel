<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2-07: "verify a sample of nutrition values for accuracy" — encoded as
 * a permanent regression test rather than a one-off manual check. Uses a
 * tiny fixture (tests/Fixtures/usda/) with real rows lifted from the
 * actual USDA SR Legacy CSVs, not the full 36MB dataset — same import
 * code path, without a slow test suite.
 */
class ImportUsdaFoodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_known_foods_with_accurate_macros(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')])
            ->assertSuccessful();

        $egg = Food::query()->where('usda_fdc_id', 173424)->firstOrFail();
        $this->assertSame('Egg, whole, cooked, hard-boiled', $egg->name_en);
        $this->assertEquals(155.0, $egg->calories_per_100g);
        $this->assertEquals(12.6, round((float) $egg->protein_g_per_100g, 1));
        $this->assertEquals(10.6, round((float) $egg->fat_g_per_100g, 1));
        $this->assertEquals(1.1, round((float) $egg->carbs_g_per_100g, 1));
        $this->assertSame('usda', $egg->source);
        $this->assertSame('approved', $egg->status);

        $chicken = Food::query()->where('usda_fdc_id', 171477)->firstOrFail();
        $this->assertEquals(165.0, $chicken->calories_per_100g);
        $this->assertEquals(31.0, round((float) $chicken->protein_g_per_100g, 1));

        $spinach = Food::query()->where('usda_fdc_id', 168462)->firstOrFail();
        $this->assertEquals(23.0, $spinach->calories_per_100g);
        $this->assertEquals(2.2, round((float) $spinach->fiber_g_per_100g, 1));
    }

    public function test_it_skips_foods_with_no_energy_value(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')])
            ->expectsOutputToContain('Skipped 1 with no energy value.')
            ->assertSuccessful();

        $this->assertNull(Food::query()->where('usda_fdc_id', 999999)->first());
    }

    public function test_reimporting_updates_rather_than_duplicates(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')]);
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')]);

        $this->assertSame(3, Food::query()->where('source', 'usda')->count());
    }

    public function test_it_reports_a_missing_source_directory_clearly(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/does-not-exist')])
            ->assertFailed();
    }
}
