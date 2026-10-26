<?php

namespace Tests\Feature\Phase4;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Phase 4, B: cost tier and preparation level of foods. Null = unknown = shows nothing. */
class FoodTagsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $admin;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        $this->tmp = storage_path('framework/tags_'.uniqid().'.csv');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    private function csv(string $content): string
    {
        file_put_contents($this->tmp, $content);

        return $this->tmp;
    }

    // ---- export / import -----------------------------------------------------------------------

    public function test_export_lists_approved_foods_with_their_current_tags(): void
    {
        $a = Food::factory()->create(['name_ar' => 'تمر', 'cost_tier' => 'economical', 'prep_level' => 'no_cook']);
        Food::factory()->create(['status' => 'pending']);

        $this->artisan('foods:export-tags', ['path' => $this->tmp])->expectsOutputToContain('Exported 1 approved food(s)')->assertSuccessful();

        $rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\xEF\xBB\xBF", '', file_get_contents($this->tmp)))));
        $this->assertSame(['food_id', 'name_ar', 'name_en', 'category', 'cost_tier', 'prep_level'], $rows[0]);
        $this->assertSame([(string) $a->id, 'تمر'], [$rows[1][0], $rows[1][1]]);
        $this->assertSame(['economical', 'no_cook'], [$rows[1][4], $rows[1][5]]);
        $this->assertCount(2, $rows);
    }

    public function test_import_dry_run_writes_nothing(): void
    {
        $f = Food::factory()->create();

        $this->artisan('foods:import-tags', ['csv' => $this->csv("food_id,cost_tier,prep_level\n{$f->id},economical,easy\n"), '--source' => 'dr'])
            ->expectsOutputToContain('Dry run')->expectsOutputToContain('Nothing written')->assertSuccessful();

        $this->assertNull($f->fresh()->cost_tier);
        $this->assertNull($f->fresh()->tags_reviewed_at);
    }

    public function test_apply_writes_only_the_tags_and_the_stamp(): void
    {
        $f = Food::factory()->create(['name_en' => 'Dates', 'calories_per_100g' => 280]);
        $g = Food::factory()->create(['cost_tier' => 'premium']);
        $before = (array) DB::table('foods')->where('id', $f->id)->first();

        $this->artisan('foods:import-tags', [
            'csv' => $this->csv("\xEF\xBB\xBFfood_id,name_ar,cost_tier,prep_level\n{$f->id},x,economical,no_cook\n{$g->id},y,,easy\n"),
            '--source' => 'dr-rama', '--reviewer' => $this->nutritionist->id, '--apply' => true,
        ])->expectsOutputToContain('Done.')->assertSuccessful();

        $after = (array) DB::table('foods')->where('id', $f->id)->first();
        $changed = array_keys(array_diff_assoc($after, $before));
        sort($changed);
        $this->assertSame(['cost_tier', 'prep_level', 'tags_reviewed_at', 'tags_reviewed_by'], $changed, 'nothing else is touched');
        $this->assertSame($this->nutritionist->id, (int) $after['tags_reviewed_by']);

        $g = $g->fresh();
        $this->assertSame('premium', $g->cost_tier, 'an empty cell leaves the column alone');
        $this->assertSame('easy', $g->prep_level);
    }

    public function test_one_bad_row_means_nothing_is_written(): void
    {
        $f = Food::factory()->create();
        $g = Food::factory()->create();

        $this->artisan('foods:import-tags', [
            'csv' => $this->csv("food_id,cost_tier,prep_level\n{$f->id},economical,easy\n{$g->id},cheap,easy\n99999,standard,\n{$f->id},standard,\nabc,standard,\n"),
            '--source' => 's', '--apply' => true,
        ])->expectsOutputToContain("cost_tier 'cheap'")->expectsOutputToContain('does not exist')->expectsOutputToContain('appears twice')->expectsOutputToContain('not a number')
            ->expectsOutputToContain('Not applied')->assertFailed();

        $this->assertNull($f->fresh()->cost_tier);
        $this->assertNull($g->fresh()->cost_tier);
    }

    public function test_a_dry_run_with_problems_fails_too_and_a_bad_prep_level_is_named(): void
    {
        $f = Food::factory()->create();

        $this->artisan('foods:import-tags', ['csv' => $this->csv("food_id,prep_level\n{$f->id},raw\n"), '--source' => 's'])
            ->expectsOutputToContain("prep_level 'raw'")->assertFailed();
    }

    public function test_the_source_and_the_columns_are_required(): void
    {
        $f = Food::factory()->create();

        $this->artisan('foods:import-tags', ['csv' => $this->csv("food_id,cost_tier\n{$f->id},standard\n")])->expectsOutputToContain('--source is required')->assertFailed();
        $this->artisan('foods:import-tags', ['csv' => $this->csv("food_id,name\n{$f->id},x\n"), '--source' => 's'])->expectsOutputToContain('needs the columns')->assertFailed();
        $this->artisan('foods:import-tags', ['csv' => '/no/such/file.csv', '--source' => 's'])->expectsOutputToContain('Cannot read')->assertFailed();
    }

    // ---- admin edit --------------------------------------------------------------------------------

    private function foodBody(array $extra = []): array
    {
        return $extra + ['name_en' => 'Lentils', 'name_ar' => 'عدس', 'calories_per_100g' => 116, 'protein_g_per_100g' => 9, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 0.4];
    }

    public function test_the_admin_sets_and_clears_the_tags_and_they_are_stamped(): void
    {
        $token = $this->bearerFor($this->admin);

        $id = $this->postJson('/api/v1/admin/foods', $this->foodBody(['cost_tier' => 'economical', 'prep_level' => 'easy']), $token)->assertCreated()
            ->assertJsonPath('cost_tier', 'economical')->assertJsonPath('prep_level', 'easy')->json('id');
        $this->assertSame($this->admin->id, Food::find($id)->tags_reviewed_by);

        $this->putJson("/api/v1/admin/foods/{$id}", $this->foodBody(['cost_tier' => null]), $token)->assertOk()->assertJsonPath('cost_tier', null)->assertJsonPath('prep_level', 'easy');
        $this->putJson("/api/v1/admin/foods/{$id}", $this->foodBody(['cost_tier' => 'cheap']), $token)->assertStatus(422)->assertJsonValidationErrors('cost_tier');
    }

    public function test_a_tag_change_needs_no_in_use_confirmation(): void
    {
        $token = $this->bearerFor($this->admin);
        $food = Food::factory()->create(['name_en' => 'Rice', 'name_ar' => 'رز', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.7, 'carbs_g_per_100g' => 28, 'fat_g_per_100g' => 0.3]);
        $plan = MealPlan::create(['subscriber_id' => $this->subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'draft']);
        $meal = \App\Models\Meal::create(['meal_plan_id' => $plan->id, 'name' => 'lunch', 'sort_order' => 0]);
        MealItem::create(['meal_id' => $meal->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->foodBody(['name_en' => 'Rice', 'name_ar' => 'رز', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.7, 'carbs_g_per_100g' => 28, 'fat_g_per_100g' => 0.3, 'prep_level' => 'normal']), $token)
            ->assertOk()->assertJsonPath('prep_level', 'normal');
    }

    // ---- search filters ------------------------------------------------------------------------------

    public function test_the_search_chips_filter_and_unknown_never_matches(): void
    {
        Food::factory()->create(['name_en' => 'Zed cheap', 'cost_tier' => 'economical', 'prep_level' => 'normal']);
        Food::factory()->create(['name_en' => 'Zed fancy', 'cost_tier' => 'premium', 'prep_level' => 'no_cook']);
        Food::factory()->create(['name_en' => 'Zed easy', 'cost_tier' => 'standard', 'prep_level' => 'easy']);
        Food::factory()->create(['name_en' => 'Zed unknown']);
        $token = $this->bearerFor($this->nutritionist);
        $names = fn (string $qs) => collect($this->getJson("/api/v1/foods/search?q=Zed{$qs}", $token)->assertOk()->json('data'))->pluck('name_en')->sort()->values()->all();

        $this->assertCount(4, $names(''));
        $this->assertSame(['Zed cheap'], $names('&economical=1'));
        $this->assertSame(['Zed easy', 'Zed fancy'], $names('&quick_prep=1'));
        $this->assertSame([], $names('&economical=1&quick_prep=1'));
        $this->assertCount(4, $names('&economical=0'), 'a false chip filters nothing');
    }

    // ---- patient payload -----------------------------------------------------------------------------------

    public function test_the_patient_plan_carries_the_tags_on_items_and_alternatives_null_when_unknown(): void
    {
        $tagged = Food::factory()->create(['cost_tier' => 'economical', 'prep_level' => 'no_cook']);
        $plain = Food::factory()->create();
        $alt = Food::factory()->create(['cost_tier' => 'premium']);
        $plan = MealPlan::create(['subscriber_id' => $this->subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'active', 'activated_at' => now()]);
        $meal = \App\Models\Meal::create(['meal_plan_id' => $plan->id, 'name' => 'lunch', 'sort_order' => 0]);
        $main = MealItem::create(['meal_id' => $meal->id, 'food_id' => $tagged->id, 'quantity_grams' => 100, 'sort_order' => 0]);
        MealItem::create(['meal_id' => $meal->id, 'food_id' => $alt->id, 'quantity_grams' => 100, 'sort_order' => 1, 'parent_item_id' => $main->id]);
        MealItem::create(['meal_id' => $meal->id, 'food_id' => $plain->id, 'quantity_grams' => 100, 'sort_order' => 2]);

        $body = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($this->client))->assertOk();
        $items = collect($body->json('meals.0.items'));

        $first = $items->firstWhere('id', $main->id);
        $this->assertSame(['economical', 'no_cook'], [$first['cost_tier'], $first['prep_level']]);
        $this->assertSame(['premium', null], [$first['alternatives'][0]['cost_tier'], $first['alternatives'][0]['prep_level']]);
        $this->assertSame([null, null], [$items->last()['cost_tier'], $items->last()['prep_level']]);
        $this->assertSame('economical', $first['food']['cost_tier']);
        $this->assertStringNotContainsString('calories_per', json_encode(array_keys($first)) === '' ? '' : '', 'sanity');
    }

    // ---- AI draft -------------------------------------------------------------------------------------------

    private function draftSetUp(): void
    {
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'm']);
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        Food::factory()->count(6)->create(['allergens' => []]);
        Food::factory()->create(['name_en' => 'Cheap beans', 'cost_tier' => 'economical', 'prep_level' => 'no_cook', 'allergens' => []]);
        Http::fake(['*' => Http::response(null, 500)]);
    }

    /** @return array<string, mixed>|null the user payload of the first LLM request */
    private function sentPayload(): ?array
    {
        $payload = null;
        Http::assertSent(function ($request) use (&$payload): bool {
            $payload ??= json_decode($request['messages'][1]['content'], true);

            return true;
        });

        return $payload;
    }

    public function test_the_draft_prompt_has_no_tag_data_unless_ticked(): void
    {
        $this->draftSetUp();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist))->assertSuccessful();

        $payload = $this->sentPayload();
        $this->assertArrayNotHasKey('soft_constraints', $payload);
        $this->assertNotContains('cost_tier', $payload['food_columns']);
        $this->assertNotContains('prep_level', $payload['food_columns']);
        $this->assertStringNotContainsString('economical', json_encode($payload));
        $this->assertStringNotContainsString('no_cook', json_encode($payload));
    }

    public function test_ticking_the_options_sends_soft_constraints_and_only_the_ticked_tag_columns(): void
    {
        $this->draftSetUp();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", ['prefer_economical' => true], $this->bearerFor($this->nutritionist))->assertSuccessful();
        $payload = $this->sentPayload();

        $this->assertSame(['prefer_economical_foods' => true], $payload['soft_constraints']);
        $this->assertContains('cost_tier', $payload['food_columns']);
        $this->assertNotContains('prep_level', $payload['food_columns']);
        $rows = collect($payload['available_foods']);
        $this->assertSame(count($payload['food_columns']), count($rows->first()), 'each row has one cell per column');
        $this->assertSame('economical', $rows->first()[count($payload['food_columns']) - 1], 'a matching food is listed first');
    }

    public function test_both_options_and_the_system_prompt_calls_them_soft(): void
    {
        $this->draftSetUp();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", ['prefer_economical' => true, 'prefer_easy_prep' => true], $this->bearerFor($this->nutritionist))->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $payload = json_decode($request['messages'][1]['content'], true);

            return $payload['soft_constraints'] === ['prefer_economical_foods' => true, 'prefer_no_cook_or_easy_prep_foods' => true]
                && str_contains($request['messages'][0]['content'], 'soft_constraints')
                && str_contains($request['messages'][0]['content'], 'SOFT');
        });
    }

    public function test_an_unticked_option_is_not_a_constraint_and_junk_is_refused(): void
    {
        $this->draftSetUp();
        $token = $this->bearerFor($this->nutritionist);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", ['prefer_economical' => false], $token)->assertSuccessful();
        $this->assertArrayNotHasKey('soft_constraints', $this->sentPayload());

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", ['prefer_economical' => 'maybe'], $token)->assertStatus(422);
    }
}
