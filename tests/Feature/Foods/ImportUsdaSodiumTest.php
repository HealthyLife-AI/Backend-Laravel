<?php

namespace Tests\Feature\Foods;

use App\Console\Commands\Foods\ImportUsdaSodium;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * foods:import-usda-sodium fills foods.sodium_mg_per_100g from the USDA
 * food_nutrient.csv (nutrient 1093). Small fixture CSVs only: the real
 * download is never used in tests.
 *
 * Fixture (tests/Fixtures/usda_sodium): fdc 1001 = 900 mg, 1002 = 300,
 * 1003 = 55000 (out of range), 1004 = 120, 1005 = 700, 1006 = 400 (no food),
 * 1007 has no sodium row at all.
 */
class ImportUsdaSodiumTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private const PATH = 'tests/Fixtures/usda_sodium';

    private function sodiumImport(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('foods:import-usda-sodium', ['--path' => base_path(self::PATH)] + $options);
    }

    private function food(int $fdc, array $attrs = []): Food
    {
        return Food::factory()->create(['usda_fdc_id' => $fdc, 'allergens' => []] + $attrs);
    }

    /** @return array<string, mixed> the whole row, for "nothing else changed" checks */
    private function row(Food $food): array
    {
        return (array) DB::table('foods')->where('id', $food->id)->first();
    }

    public function test_dry_run_writes_nothing_and_prints_counts_and_the_top_list_only(): void
    {
        $salty = $this->food(1001, ['name_ar' => 'مالح']);
        $this->food(1002);
        $before = DB::table('foods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->sodiumImport()
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('foods matched')
            ->expectsOutputToContain('would update')
            ->expectsOutputToContain('Highest sodium among matched foods')
            ->expectsOutputToContain('مالح')
            ->expectsOutputToContain('Nothing written')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('foods')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertNull($salty->fresh()->sodium_mg_per_100g);
    }

    public function test_apply_fills_only_null_sodium_and_overwrite_replaces(): void
    {
        $empty = $this->food(1001);
        $set = $this->food(1005, ['sodium_mg_per_100g' => 50]);

        $this->sodiumImport(['--apply' => true])->assertSuccessful();

        $this->assertSame('900.0', (string) $empty->fresh()->sodium_mg_per_100g);
        $this->assertSame('50.0', (string) $set->fresh()->sodium_mg_per_100g, 'existing sodium is left alone');

        $this->sodiumImport(['--apply' => true, '--overwrite' => true])->assertSuccessful();

        $this->assertSame('700.0', (string) $set->fresh()->sodium_mg_per_100g);
        $this->assertSame('900.0', (string) $empty->fresh()->sodium_mg_per_100g);
    }

    public function test_it_never_touches_anything_but_sodium_and_the_review_stamp(): void
    {
        $food = $this->food(1001, ['name_ar' => 'اسم', 'calories_per_100g' => 123.4, 'carbs_g_per_100g' => 30, 'glycemic_index' => 55, 'allergens' => ['egg'], 'status' => 'approved']);
        $before = $this->row($food);

        $this->sodiumImport(['--apply' => true])->assertSuccessful();

        $after = $this->row($food);
        foreach (array_keys($before) as $column) {
            if (in_array($column, ['sodium_mg_per_100g', 'nutrition_reviewed_at', 'nutrition_review_source'], true)) {
                continue;
            }
            $this->assertEquals($before[$column], $after[$column], "{$column} must not change");
        }
    }

    public function test_a_gi_review_keeps_its_time_and_gets_the_usda_source_appended_and_an_unreviewed_food_is_stamped(): void
    {
        $reviewedAt = '2026-09-01 10:00:00';
        $gi = $this->food(1004, ['glycemic_index' => 60, 'nutrition_reviewed_at' => $reviewedAt, 'nutrition_review_source' => 'Atkinson 2021', 'nutrition_reviewed_by' => null]);
        $fresh = $this->food(1001);

        $this->sodiumImport(['--apply' => true])->assertSuccessful();

        $gi = $gi->fresh();
        $this->assertSame($reviewedAt, $gi->nutrition_reviewed_at->format('Y-m-d H:i:s'), 'the GI review time is kept');
        $this->assertSame('Atkinson 2021 + USDA SR Legacy 2018 (sodium)', $gi->nutrition_review_source);
        $this->assertSame(60, $gi->glycemic_index);
        $this->assertSame('120.0', (string) $gi->sodium_mg_per_100g);

        $fresh = $fresh->fresh();
        $this->assertNotNull($fresh->nutrition_reviewed_at);
        $this->assertSame('USDA SR Legacy 2018 (sodium)', $fresh->nutrition_review_source);
        $this->assertNull($fresh->nutrition_reviewed_by, 'an automated import names no reviewer');
    }

    public function test_the_source_text_rules(): void
    {
        $this->assertSame('USDA SR Legacy 2018 (sodium)', ImportUsdaSodium::reviewSource(null));
        $this->assertSame('USDA SR Legacy 2018 (sodium)', ImportUsdaSodium::reviewSource('  '));
        // Already names USDA: not appended twice.
        $this->assertSame('USDA SR Legacy 2018 (sodium)', ImportUsdaSodium::reviewSource('USDA SR Legacy 2018 (sodium)'));
        $this->assertSame('USDA FDC', ImportUsdaSodium::reviewSource('USDA FDC'));

        // Respects the 255-character column: the old text is shortened, the suffix kept whole.
        $long = ImportUsdaSodium::reviewSource(str_repeat('ب', 400));
        $this->assertSame(255, mb_strlen($long));
        $this->assertStringEndsWith(' + USDA SR Legacy 2018 (sodium)', $long);
    }

    public function test_an_out_of_range_value_is_skipped_and_reported(): void
    {
        $bad = $this->food(1003);

        $this->sodiumImport(['--apply' => true])
            ->expectsOutputToContain('out of range (skipped)')
            ->assertSuccessful();

        $this->assertNull($bad->fresh()->sodium_mg_per_100g);
        $this->assertNull($bad->fresh()->nutrition_reviewed_at);

        // The report counts it (one row in the fixture).
        $this->sodiumImport()->expectsTable(['', 'foods'], [
            ['foods matched', 0],
            ['would update', 0],
            ['already had sodium (left as is)', 0],
            ['out of range (skipped)', 1],
            ['fdc ids in CSV with no matching food', 5],
        ])->assertSuccessful();
    }

    public function test_foods_without_a_usda_id_and_unlisted_foods_are_untouched_and_unmatched_ids_are_counted(): void
    {
        $dish = Food::factory()->create(['usda_fdc_id' => null, 'allergens' => []]);
        $noRow = $this->food(1007);
        $one = $this->food(1001);
        $before = [$this->row($dish), $this->row($noRow)];

        $this->sodiumImport(['--apply' => true])->assertSuccessful();

        $this->assertSame($before, [$this->row($dish), $this->row($noRow)]);
        $this->assertSame('900.0', (string) $one->fresh()->sodium_mg_per_100g);

        // 1001..1006 carry a sodium row; only 1001 has a food, 1003 is out of range => 1002, 1004, 1005, 1006 unmatched.
        $this->sodiumImport()->expectsTable(['', 'foods'], [
            ['foods matched', 1],
            ['would update', 0],
            ['already had sodium (left as is)', 1],
            ['out of range (skipped)', 1],
            ['fdc ids in CSV with no matching food', 4],
        ])->assertSuccessful();
    }

    public function test_a_missing_csv_prints_the_download_hint_and_fails(): void
    {
        $this->artisan('foods:import-usda-sodium', ['--path' => base_path('tests/Fixtures/does-not-exist')])
            ->expectsOutputToContain('Expected food_nutrient.csv')
            ->expectsOutputToContain('https://fdc.nal.usda.gov/fdc-datasets/FoodData_Central_sr_legacy_food_csv_2018-04.zip')
            ->assertFailed();
    }

    public function test_it_works_in_small_chunks(): void
    {
        $a = $this->food(1001);
        $b = $this->food(1002);
        $c = $this->food(1005);

        $this->sodiumImport(['--apply' => true, '--chunk' => 1])->assertSuccessful();

        $this->assertSame(['900.0', '300.0', '700.0'], [(string) $a->fresh()->sodium_mg_per_100g, (string) $b->fresh()->sodium_mg_per_100g, (string) $c->fresh()->sodium_mg_per_100g]);
    }

    // ---- the warning, end to end -------------------------------------------------

    public function test_after_the_import_only_a_hypertensive_patient_is_warned_and_only_above_600(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $nutritionist = User::factory()->nutritionist()->create();
        $token = $this->bearerFor($nutritionist);

        $salty = $this->food(1001, ['name_ar' => 'مالح']);   // 900 mg
        $mild = $this->food(1002, ['name_ar' => 'خفيف']);    // 300 mg

        $this->sodiumImport(['--apply' => true])->assertSuccessful();

        $patient = function (?string $condition) use ($nutritionist): Subscriber {
            $s = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
            HealthProfile::create(['subscriber_id' => $s->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800]);
            if ($condition !== null) {
                PatientCondition::create(['subscriber_id' => $s->id, 'code' => $condition]);
            }

            return $s;
        };
        $warnings = function (Subscriber $s) use ($token, $salty, $mild) {
            return $this->postJson("/api/v1/clients/{$s->id}/meal-plans", [
                'meals' => [['name' => 'lunch', 'items' => [['food_id' => $salty->id, 'quantity_grams' => 100], ['food_id' => $mild->id, 'quantity_grams' => 100]]]],
            ], $token)->assertCreated()->json('nutrition_warnings');
        };

        $hypertensive = $warnings($patient('hypertension'));
        $this->assertCount(1, $hypertensive);
        $this->assertSame('sodium', $hypertensive[0]['type']);
        $this->assertSame($salty->id, $hypertensive[0]['food_id']);
        $this->assertEquals(900, $hypertensive[0]['sodium_mg_per_100g']);

        $this->assertSame([], $warnings($patient(null)));
        $this->assertSame([], $warnings($patient('diabetes_type2')));
    }

    // ---- foods:import-usda keeps sodium too ----------------------------------------

    public function test_import_usda_stores_sodium_for_a_new_food_with_the_review_stamp(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')])->assertSuccessful();

        $egg = Food::query()->where('usda_fdc_id', 173424)->firstOrFail();
        $this->assertSame('124.0', (string) $egg->sodium_mg_per_100g);
        $this->assertNotNull($egg->nutrition_reviewed_at);
        $this->assertSame('USDA SR Legacy 2018 (sodium)', $egg->nutrition_review_source);

        // A food with no sodium row gets none, and no stamp.
        $chicken = Food::query()->where('usda_fdc_id', 171477)->firstOrFail();
        $this->assertNull($chicken->sodium_mg_per_100g);
        $this->assertNull($chicken->nutrition_reviewed_at);
    }

    public function test_reimporting_usda_foods_leaves_an_existing_foods_sodium_and_gi_review_alone(): void
    {
        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')])->assertSuccessful();
        $egg = Food::query()->where('usda_fdc_id', 173424)->firstOrFail();
        DB::table('foods')->where('id', $egg->id)->update(['sodium_mg_per_100g' => 77, 'glycemic_index' => 30, 'nutrition_review_source' => 'Atkinson 2021']);

        $this->artisan('foods:import-usda', ['--path' => base_path('tests/Fixtures/usda')])->assertSuccessful();

        $egg = $egg->fresh();
        $this->assertSame('77.0', (string) $egg->sodium_mg_per_100g);
        $this->assertSame(30, $egg->glycemic_index);
        $this->assertSame('Atkinson 2021', $egg->nutrition_review_source);
    }
}
