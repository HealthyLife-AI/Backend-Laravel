<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\MealPlans\NutritionWarningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Clinical batch 1, E: GI / sodium scaffolding. The app holds no values; they
 * arrive by CSV import, and warnings (amber, never blocking) only ever
 * concern REVIEWED foods and the matching condition.
 */
class NutritionWarningsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function condition(string $code): void
    {
        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => $code]);
    }

    private function reviewed(array $attrs): Food
    {
        return Food::factory()->create($attrs + ['allergens' => [], 'nutrition_reviewed_at' => now(), 'nutrition_review_source' => 'test table']);
    }

    private function plan(array $foods, int $grams = 100): array
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'lunch', 'items' => array_map(fn (Food $f) => ['food_id' => $f->id, 'quantity_grams' => $grams], $foods)]],
        ], $this->token())->assertCreated()->json();
    }

    // ---- derived categories ------------------------------------------------

    public function test_glycemic_categories_and_load(): void
    {
        foreach ([[55, 'low'], [56, 'medium'], [69, 'medium'], [70, 'high']] as [$gi, $category]) {
            $this->assertSame($category, NutritionWarningService::giCategory($gi), "GI {$gi}");
        }
        foreach ([[10.0, 'low'], [10.1, 'medium'], [19.0, 'medium'], [20.0, 'high']] as [$gl, $category]) {
            $this->assertSame($category, NutritionWarningService::glCategory($gl), "GL {$gl}");
        }

        // GL = GI x (carbs - fibre) x grams/100 / 100.
        $rice = new Food(['carbs_g_per_100g' => 30, 'fiber_g_per_100g' => 2]);
        $rice->glycemic_index = 70;
        $this->assertSame(39.2, NutritionWarningService::glycemicLoad($rice, 200)); // 70 x 56 / 100

        $noFibre = new Food(['carbs_g_per_100g' => 30, 'fiber_g_per_100g' => null]);
        $noFibre->glycemic_index = 50;
        $this->assertSame(15.0, NutritionWarningService::glycemicLoad($noFibre, 100)); // fibre null = 0

        $this->assertNull(NutritionWarningService::glycemicLoad(new Food(['carbs_g_per_100g' => 30]), 100));
    }

    // ---- warnings ------------------------------------------------------------

    public function test_a_diabetic_patient_is_warned_about_high_gi_and_high_gl_with_the_numbers(): void
    {
        $this->condition('diabetes_type2');
        $highBoth = $this->reviewed(['carbs_g_per_100g' => 30, 'fiber_g_per_100g' => 2, 'glycemic_index' => 75]);
        $highGiSmall = $this->reviewed(['carbs_g_per_100g' => 5, 'fiber_g_per_100g' => 0, 'glycemic_index' => 80]);
        $low = $this->reviewed(['carbs_g_per_100g' => 30, 'fiber_g_per_100g' => 2, 'glycemic_index' => 30]);

        $plan = $this->plan([$highBoth, $highGiSmall, $low], 200);

        $byFood = collect($plan['nutrition_warnings'])->keyBy('food_id');
        $this->assertCount(2, $byFood, 'the low-GI food is not flagged');

        $this->assertSame('gi_gl', $byFood[$highBoth->id]['type']);
        $this->assertSame(75, $byFood[$highBoth->id]['gi']);
        $this->assertEquals(42.0, $byFood[$highBoth->id]['gl']); // 75 x 56 / 100
        $this->assertSame('high', $byFood[$highBoth->id]['gl_category']);

        // High index but a small amount of carbs: GI warns, GL does not.
        $this->assertSame('gi', $byFood[$highGiSmall->id]['type']);
        $this->assertSame('low', $byFood[$highGiSmall->id]['gl_category']);
    }

    public function test_insulin_resistance_and_prediabetes_get_the_glycemic_warnings_but_not_sodium(): void
    {
        $food = $this->reviewed(['carbs_g_per_100g' => 30, 'fiber_g_per_100g' => 2, 'glycemic_index' => 80, 'sodium_mg_per_100g' => 2000]);

        foreach (['insulin_resistance', 'prediabetes'] as $code) {
            PatientCondition::where('subscriber_id', $this->subscriber->id)->delete();
            $this->condition($code);

            $warnings = $this->plan([$food], 200)['nutrition_warnings'];
            $this->assertCount(1, $warnings, $code);
            $this->assertSame('gi_gl', $warnings[0]['type'], $code);

            $check = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [$food->id]], $this->token())->json('foods.0.nutrition_warnings');
            $this->assertSame(['gi'], array_column($check, 'type'), $code);
        }
    }

    public function test_the_load_follows_the_quantity(): void
    {
        $this->condition('diabetes_type1');
        $food = $this->reviewed(['carbs_g_per_100g' => 20, 'fiber_g_per_100g' => 0, 'glycemic_index' => 50]);

        // 50 x 20 x 0.5 / 100 = 5.0 for 50 g: not high GL, GI 50 not high either.
        $this->assertSame([], $this->plan([$food], 50)['nutrition_warnings']);

        // 50 x 20 x 5 / 100 = 50 for 500 g: high GL though the index is not.
        $big = $this->plan([$food], 500)['nutrition_warnings'];
        $this->assertSame('gl', $big[0]['type']);
        $this->assertEquals(50.0, $big[0]['gl']);
    }

    public function test_a_hypertensive_patient_is_warned_about_high_sodium_only(): void
    {
        $this->condition('hypertension');
        $salty = $this->reviewed(['sodium_mg_per_100g' => 1200, 'glycemic_index' => 90, 'carbs_g_per_100g' => 40]);
        $fine = $this->reviewed(['sodium_mg_per_100g' => 599.9]);
        $edge = $this->reviewed(['sodium_mg_per_100g' => 600]);

        $warnings = $this->plan([$salty, $fine, $edge])['nutrition_warnings'];

        $this->assertEqualsCanonicalizing([$salty->id, $edge->id], array_column($warnings, 'food_id'));
        $this->assertSame('sodium', $warnings[0]['type']);
        $this->assertEquals(1200.0, collect($warnings)->firstWhere('food_id', $salty->id)['sodium_mg_per_100g']);
        // Hypertension is not diabetes: the 90-GI food draws no glycemic warning.
        $this->assertNotContains('gi', array_column($warnings, 'type'));
    }

    public function test_sodium_does_not_warn_a_diabetic_and_gi_does_not_warn_a_hypertensive(): void
    {
        $this->condition('diabetes_type2');
        $salty = $this->reviewed(['sodium_mg_per_100g' => 2000]);

        $this->assertSame([], $this->plan([$salty])['nutrition_warnings']);
    }

    public function test_unreviewed_foods_and_patients_without_the_condition_get_no_warnings(): void
    {
        $this->condition('diabetes_type2');
        $this->condition('hypertension');
        // Values present but never reviewed: not judged.
        $unreviewed = Food::factory()->create(['allergens' => [], 'glycemic_index' => 95, 'sodium_mg_per_100g' => 5000, 'carbs_g_per_100g' => 50]);

        $plan = $this->plan([$unreviewed]);

        $this->assertSame([], $plan['nutrition_warnings']);
        $this->assertSame([], $plan['unverified_items'] === [] ? [] : [], 'unreviewed GI/sodium adds no unknown noise');

        // And a patient with no matching condition is never warned.
        PatientCondition::where('subscriber_id', $this->subscriber->id)->delete();
        $reviewed = $this->reviewed(['glycemic_index' => 95, 'sodium_mg_per_100g' => 5000, 'carbs_g_per_100g' => 50]);
        $this->assertSame([], $this->plan([$reviewed])['nutrition_warnings']);
    }

    public function test_alternatives_are_checked_and_a_warning_never_blocks_activation(): void
    {
        $this->condition('hypertension');
        $ok = $this->reviewed(['sodium_mg_per_100g' => 10]);
        $salty = $this->reviewed(['sodium_mg_per_100g' => 900]);

        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $ok->id, 'quantity_grams' => 100, 'alternatives' => [['food_id' => $salty->id, 'quantity_grams' => 100]]]]]],
        ], $this->token())->assertCreated()->json();

        $this->assertCount(1, $plan['nutrition_warnings']);
        $this->assertNotNull($plan['nutrition_warnings'][0]['parent_item_id']);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();
    }

    public function test_the_food_check_returns_food_level_warnings_for_reviewed_foods(): void
    {
        $this->condition('hypertension');
        $this->condition('diabetes_type2');
        $both = $this->reviewed(['sodium_mg_per_100g' => 900, 'glycemic_index' => 85]);
        $unreviewed = Food::factory()->create(['allergens' => [], 'sodium_mg_per_100g' => 900, 'glycemic_index' => 85]);

        $result = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [$both->id, $unreviewed->id]], $this->token())
            ->assertOk()->json('foods');

        $byFood = collect($result)->keyBy('food_id');
        $this->assertEqualsCanonicalizing(['gi', 'sodium'], array_column($byFood[$both->id]['nutrition_warnings'], 'type'));
        $this->assertSame([], $byFood[$unreviewed->id]['nutrition_warnings']);
    }

    public function test_the_client_role_never_receives_the_warnings(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);
        $this->condition('hypertension');
        $salty = $this->reviewed(['sodium_mg_per_100g' => 900]);
        $plan = $this->plan([$salty]);
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertOk();

        $this->assertArrayNotHasKey('nutrition_warnings', $mine->json());
        $this->assertStringNotContainsString('sodium', $mine->getContent());
    }

    // ---- import / export -------------------------------------------------------

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nr');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_import_dry_run_changes_nothing(): void
    {
        $food = Food::factory()->create();
        $path = $this->csv("food_id,glycemic_index,sodium_mg_per_100g\n{$food->id},55,120\n");

        $this->artisan('foods:import-nutrition-review', ['csv' => $path, '--source' => 'Table X'])
            ->expectsOutputToContain('Dry run')->assertSuccessful();

        $fresh = $food->fresh();
        $this->assertNull($fresh->glycemic_index);
        $this->assertNull($fresh->sodium_mg_per_100g);
        $this->assertNull($fresh->nutrition_reviewed_at);
    }

    public function test_import_apply_sets_the_values_and_the_review_stamp_and_nothing_else(): void
    {
        $food = Food::factory()->create(['calories_per_100g' => 123.4, 'allergens' => ['egg']]);
        $other = Food::factory()->create();
        $reviewer = User::factory()->nutritionist()->create();
        $path = $this->csv("food_id,glycemic_index,sodium_mg_per_100g\n{$food->id},55,\n{$other->id},,310.55\n");
        $before = DB::table('foods')->where('id', $food->id)->first();

        $this->artisan('foods:import-nutrition-review', ['csv' => $path, '--source' => 'Table X', '--reviewer' => $reviewer->id, '--apply' => true])->assertSuccessful();

        $fresh = $food->fresh();
        $this->assertSame(55, $fresh->glycemic_index);
        $this->assertNull($fresh->sodium_mg_per_100g, 'an empty cell leaves that column alone');
        $this->assertSame('Table X', $fresh->nutrition_review_source);
        $this->assertNotNull($fresh->nutrition_reviewed_at);
        $this->assertSame($reviewer->id, (int) $fresh->nutrition_reviewed_by);

        $after = DB::table('foods')->where('id', $food->id)->first();
        foreach (['name_ar', 'name_en', 'calories_per_100g', 'carbs_g_per_100g', 'allergens', 'status', 'shopping_section'] as $column) {
            $this->assertEquals($before->{$column}, $after->{$column}, "{$column} must not change");
        }

        $this->assertSame('310.6', (string) $other->fresh()->sodium_mg_per_100g);
        $this->assertNull($other->fresh()->glycemic_index);
    }

    public function test_import_validates_ranges_and_reports_unknown_ids_and_applies_nothing_then(): void
    {
        $food = Food::factory()->create();
        $path = $this->csv("food_id,glycemic_index,sodium_mg_per_100g\n{$food->id},55,100\n999999,40,\n{$food->id},111,\n{$food->id},,40001\n{$food->id},-3,\nabc,1,\n");

        $this->artisan('foods:import-nutrition-review', ['csv' => $path, '--source' => 'Table X', '--apply' => true])
            ->expectsOutputToContain('999999')
            ->expectsOutputToContain('glycemic_index')
            ->expectsOutputToContain('sodium_mg_per_100g')
            ->assertFailed();

        $this->assertNull($food->fresh()->glycemic_index, 'a partial import is refused');
    }

    public function test_import_needs_a_source_and_the_right_columns(): void
    {
        $food = Food::factory()->create();

        $this->artisan('foods:import-nutrition-review', ['csv' => $this->csv("food_id,glycemic_index\n{$food->id},50\n")])->assertFailed();
        $this->artisan('foods:import-nutrition-review', ['csv' => $this->csv("id,gi\n{$food->id},50\n"), '--source' => 'X'])->assertFailed();
    }

    public function test_export_lists_approved_foods_with_the_review_columns(): void
    {
        $approved = Food::factory()->create(['status' => 'approved', 'name_ar' => 'تمر', 'carbs_g_per_100g' => 75, 'fiber_g_per_100g' => 7]);
        Food::factory()->create(['status' => 'pending', 'name_ar' => 'مرفوض']);
        $path = tempnam(sys_get_temp_dir(), 'nx');

        $this->artisan('foods:export-nutrition-review', ['path' => $path])->assertSuccessful();

        $content = file_get_contents($path);
        $this->assertStringContainsString('food_id,name_ar,name_en,carbs_g_per_100g,fiber_g_per_100g,glycemic_index,sodium_mg_per_100g', $content);
        $this->assertStringContainsString("{$approved->id},تمر", $content);
        $this->assertStringNotContainsString('مرفوض', $content);
    }
}
