<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\Meal;
use App\Models\MealLog;
use App\Models\MealPlan;
use App\Models\MealItem;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * A nutritionist suggests a food's glycemic index, sodium and tags; the admin reviews them.
 * The review stamp is the admin's, never the nutritionist's.
 */
class FoodNutritionFieldsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $admin;

    private User $nutritionist;

    private array $adminToken;

    private array $nutritionistToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Ali']);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->adminToken = $this->bearerFor($this->admin);
        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
    }

    /** @param  array<string, mixed>  $extra */
    private function body(array $extra = []): array
    {
        return $extra + ['name_ar' => 'رز', 'name_en' => 'Rice', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.7, 'carbs_g_per_100g' => 28, 'fat_g_per_100g' => 0.3];
    }

    private function submit(array $extra = [])
    {
        return $this->postJson('/api/v1/foods', $this->body($extra), $this->nutritionistToken);
    }

    // ---- nutritionist submission ----------------------------------------------------------------

    public function test_a_glycemic_index_or_sodium_without_a_source_is_refused(): void
    {
        $this->submit(['glycemic_index' => 70])->assertStatus(422)->assertJsonValidationErrors('nutrition_review_source');
        $this->submit(['sodium_mg_per_100g' => 5])->assertStatus(422)->assertJsonValidationErrors('nutrition_review_source');
        $this->submit(['glycemic_index' => 70, 'nutrition_review_source' => ''])->assertStatus(422);
        $this->assertSame(0, Food::count());
    }

    public function test_a_source_is_not_needed_when_neither_value_is_sent(): void
    {
        $this->submit()->assertCreated();
        $this->submit(['glycemic_index' => null, 'sodium_mg_per_100g' => null, 'name_en' => 'Plain'])->assertCreated();
    }

    public function test_the_values_are_stored_on_the_pending_food_and_never_stamped_reviewed(): void
    {
        $id = $this->submit(['glycemic_index' => 73, 'sodium_mg_per_100g' => 1.5, 'nutrition_review_source' => 'Atkinson 2021 table', 'cost_tier' => 'economical', 'prep_level' => 'easy'])
            ->assertCreated()->assertJsonPath('status', 'pending')->json('id');

        $food = Food::find($id);
        $this->assertSame(73, (int) $food->glycemic_index);
        $this->assertEquals(1.5, (float) $food->sodium_mg_per_100g);
        $this->assertSame('Atkinson 2021 table', $food->nutrition_review_source);
        $this->assertSame(['economical', 'easy'], [$food->cost_tier, $food->prep_level]);
        $this->assertNull($food->nutrition_reviewed_at);
        $this->assertNull($food->nutrition_reviewed_by);
        $this->assertNull($food->tags_reviewed_at, 'a suggested tag is not a reviewed tag');
    }

    public function test_a_nutritionist_cannot_smuggle_a_review_stamp(): void
    {
        $id = $this->submit(['glycemic_index' => 50, 'nutrition_review_source' => 's', 'nutrition_reviewed_by' => $this->admin->id, 'nutrition_reviewed_at' => '2026-01-01', 'status' => 'approved'])
            ->assertCreated()->json('id');

        $food = Food::find($id);
        $this->assertNull($food->nutrition_reviewed_by);
        $this->assertNull($food->nutrition_reviewed_at);
        $this->assertSame('pending', $food->status);
    }

    public function test_the_value_rules(): void
    {
        $with = fn (array $x) => $this->submit($x + ['nutrition_review_source' => 's']);

        $with(['glycemic_index' => 110])->assertCreated();
        $with(['glycemic_index' => 111])->assertStatus(422)->assertJsonValidationErrors('glycemic_index');
        $with(['glycemic_index' => -1])->assertStatus(422);
        $with(['glycemic_index' => 55.5])->assertStatus(422);
        $with(['sodium_mg_per_100g' => 40000])->assertCreated();
        $with(['sodium_mg_per_100g' => 40000.1])->assertStatus(422)->assertJsonValidationErrors('sodium_mg_per_100g');
        $with(['sodium_mg_per_100g' => -0.1])->assertStatus(422);
        $this->submit(['nutrition_review_source' => str_repeat('م', 256)])->assertStatus(422)->assertJsonValidationErrors('nutrition_review_source');
        $this->submit(['cost_tier' => 'cheap'])->assertStatus(422)->assertJsonValidationErrors('cost_tier');
        $this->submit(['prep_level' => 'raw'])->assertStatus(422)->assertJsonValidationErrors('prep_level');
    }

    // ---- admin ------------------------------------------------------------------------------------------

    public function test_an_admin_update_of_the_glycemic_index_stamps_the_review(): void
    {
        $food = Food::factory()->create(['carbs_g_per_100g' => 28]);

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->body(['glycemic_index' => 73, 'nutrition_review_source' => 'Table A']), $this->adminToken)
            ->assertOk()->assertJsonPath('glycemic_index', 73)->assertJsonPath('nutrition_review_source', 'Table A')->assertJsonPath('nutrition_reviewer_name', 'Admin Ali');

        $food = $food->fresh();
        $this->assertSame($this->admin->id, $food->nutrition_reviewed_by);
        $this->assertNotNull($food->nutrition_reviewed_at);
    }

    public function test_an_admin_can_correct_a_pending_food_before_approving_it(): void
    {
        $id = $this->submit(['glycemic_index' => 99, 'nutrition_review_source' => 'typo table'])->assertCreated()->json('id');

        $this->putJson("/api/v1/admin/foods/{$id}", $this->body(['glycemic_index' => 69, 'nutrition_review_source' => 'Corrected table']), $this->adminToken)
            ->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('glycemic_index', 69);

        $this->assertSame($this->admin->id, Food::find($id)->nutrition_reviewed_by);
    }

    public function test_clearing_both_values_clears_the_source_too_and_keeps_the_stamp(): void
    {
        $food = Food::factory()->create();
        $food->forceFill(['glycemic_index' => 60, 'sodium_mg_per_100g' => 10, 'nutrition_review_source' => 'Table'])->save();

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->body(['glycemic_index' => null, 'sodium_mg_per_100g' => null]), $this->adminToken)
            ->assertOk()->assertJsonPath('glycemic_index', null)->assertJsonPath('sodium_mg_per_100g', null)->assertJsonPath('nutrition_review_source', null);

        $food = $food->fresh();
        $this->assertNull($food->nutrition_review_source);
        $this->assertSame($this->admin->id, $food->nutrition_reviewed_by);
    }

    public function test_clearing_one_value_keeps_the_other_and_the_source(): void
    {
        $food = Food::factory()->create();
        $food->forceFill(['glycemic_index' => 60, 'sodium_mg_per_100g' => 10, 'nutrition_review_source' => 'Table'])->save();

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->body(['glycemic_index' => null, 'nutrition_review_source' => 'Table']), $this->adminToken)->assertOk();

        $food = $food->fresh();
        $this->assertNull($food->glycemic_index);
        $this->assertEquals(10, (float) $food->sodium_mg_per_100g);
        $this->assertSame('Table', $food->nutrition_review_source);
    }

    public function test_an_omitted_field_stays_and_an_edit_of_something_else_is_not_a_review(): void
    {
        $food = Food::factory()->create(['name_en' => 'Old']);
        $food->forceFill(['glycemic_index' => 60, 'nutrition_review_source' => 'Table', 'nutrition_reviewed_at' => '2026-01-01 00:00:00', 'nutrition_reviewed_by' => $this->nutritionist->id])->save();

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->body(['name_en' => 'New']), $this->adminToken)->assertOk();

        $food = $food->fresh();
        $this->assertSame(60, (int) $food->glycemic_index);
        $this->assertSame('Table', $food->nutrition_review_source);
        $this->assertSame($this->nutritionist->id, $food->nutrition_reviewed_by, 'no GI / sodium in the request: the earlier review stands');
    }

    public function test_an_admin_create_with_values_stamps_the_review(): void
    {
        $id = $this->postJson('/api/v1/admin/foods', $this->body(['sodium_mg_per_100g' => 400, 'nutrition_review_source' => 'Label']), $this->adminToken)->assertCreated()->json('id');

        $food = Food::find($id);
        $this->assertEquals(400, (float) $food->sodium_mg_per_100g);
        $this->assertSame($this->admin->id, $food->nutrition_reviewed_by);
        $this->assertSame('approved', $food->status);
    }

    public function test_the_admin_request_follows_the_same_rules(): void
    {
        $this->postJson('/api/v1/admin/foods', $this->body(['glycemic_index' => 50]), $this->adminToken)->assertStatus(422)->assertJsonValidationErrors('nutrition_review_source');
        $this->postJson('/api/v1/admin/foods', $this->body(['glycemic_index' => 200, 'nutrition_review_source' => 's']), $this->adminToken)->assertStatus(422);
        $this->postJson('/api/v1/admin/foods', $this->body(['cost_tier' => 'cheap']), $this->adminToken)->assertStatus(422);
    }

    public function test_a_values_change_needs_no_in_use_confirmation(): void
    {
        $food = Food::factory()->create(['name_en' => 'Rice', 'name_ar' => 'رز', 'calories_per_100g' => 130, 'protein_g_per_100g' => 2.7, 'carbs_g_per_100g' => 28, 'fat_g_per_100g' => 0.3, 'fiber_g_per_100g' => null]);
        $client = User::factory()->client($this->nutritionist)->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id]);
        $plan = MealPlan::create(['subscriber_id' => $subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'draft']);
        $meal = Meal::create(['meal_plan_id' => $plan->id, 'name' => 'lunch', 'sort_order' => 0]);
        MealItem::create(['meal_id' => $meal->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->body(['glycemic_index' => 73, 'nutrition_review_source' => 'T']), $this->adminToken)->assertOk();
    }

    // ---- approve ---------------------------------------------------------------------------------------------

    public function test_approving_a_food_with_a_suggested_value_stamps_the_approver(): void
    {
        $id = $this->submit(['glycemic_index' => 73, 'nutrition_review_source' => 'T'])->assertCreated()->json('id');

        $this->postJson("/api/v1/foods/{$id}/approve", [], $this->adminToken)->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('nutrition_reviewer_name', 'Admin Ali');

        $food = Food::find($id);
        $this->assertSame($this->admin->id, $food->nutrition_reviewed_by);
        $this->assertNotNull($food->nutrition_reviewed_at);
    }

    public function test_approving_keeps_an_earlier_reviewer(): void
    {
        $id = $this->submit(['glycemic_index' => 73, 'nutrition_review_source' => 'T'])->assertCreated()->json('id');
        $this->putJson("/api/v1/admin/foods/{$id}", $this->body(['glycemic_index' => 70, 'nutrition_review_source' => 'T2']), $this->adminToken)->assertOk();
        $other = User::factory()->admin()->create();
        $otherToken = $this->bearerFor($other);

        $this->postJson("/api/v1/foods/{$id}/approve", [], $otherToken)->assertOk();

        $this->assertSame($this->admin->id, Food::find($id)->nutrition_reviewed_by);
    }

    public function test_approving_works_with_every_field_missing_and_stamps_nothing(): void
    {
        $id = $this->submit()->assertCreated()->json('id');

        $response = $this->postJson("/api/v1/foods/{$id}/approve", [], $this->adminToken)->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame(['glycemic_index', 'sodium', 'cost_tier', 'prep_level'], $response->json('missing'));
        $this->assertNull(Food::find($id)->nutrition_reviewed_by, 'nothing was supplied, nothing was reviewed');
    }

    public function test_a_nutritionist_cannot_approve(): void
    {
        $id = $this->submit()->assertCreated()->json('id');

        $this->postJson("/api/v1/foods/{$id}/approve", [], $this->nutritionistToken)->assertForbidden();
    }

    // ---- "missing" ----------------------------------------------------------------------------------------------

    private function food(array $attributes): Food
    {
        return Food::factory()->create($attributes + ['carbs_g_per_100g' => 0, 'fiber_g_per_100g' => null]);
    }

    private function missingOf(Food $food): array
    {
        return $this->getJson("/api/v1/admin/foods?q=".urlencode((string) $food->name_en), $this->adminToken)->assertOk()->json('data.0.missing');
    }

    public function test_a_meat_like_food_is_not_missing_a_glycemic_index_but_rice_is(): void
    {
        $meat = $this->food(['name_en' => 'Zz meat', 'carbs_g_per_100g' => 0]);
        $rice = $this->food(['name_en' => 'Zz rice', 'carbs_g_per_100g' => 28]);

        $this->assertSame(['sodium', 'cost_tier', 'prep_level'], $this->missingOf($meat));
        $this->assertSame(['glycemic_index', 'sodium', 'cost_tier', 'prep_level'], $this->missingOf($rice));
    }

    public function test_the_five_gram_line_uses_available_carbs_with_fibre_taken_off(): void
    {
        $this->assertContains('glycemic_index', $this->missingOf($this->food(['name_en' => 'Zz five', 'carbs_g_per_100g' => 5])), '5 g counts');
        $this->assertNotContains('glycemic_index', $this->missingOf($this->food(['name_en' => 'Zz four', 'carbs_g_per_100g' => 4.9])));
        $this->assertNotContains('glycemic_index', $this->missingOf($this->food(['name_en' => 'Zz fibrous', 'carbs_g_per_100g' => 20, 'fiber_g_per_100g' => 16])), '20 - 16 = 4');
        $this->assertContains('glycemic_index', $this->missingOf($this->food(['name_en' => 'Zz unknown fibre', 'carbs_g_per_100g' => 6, 'fiber_g_per_100g' => null])), 'a null fibre is 0');
    }

    public function test_a_filled_field_is_not_missing(): void
    {
        $food = $this->food(['name_en' => 'Zz full', 'carbs_g_per_100g' => 30, 'glycemic_index' => 50, 'sodium_mg_per_100g' => 3, 'cost_tier' => 'standard', 'prep_level' => 'normal']);

        $this->assertSame([], $this->missingOf($food));
    }

    public function test_zero_is_a_value_not_a_gap(): void
    {
        $food = $this->food(['name_en' => 'Zz zero', 'carbs_g_per_100g' => 30, 'glycemic_index' => 0, 'sodium_mg_per_100g' => 0]);

        $this->assertNotContains('sodium', $this->missingOf($food));
        $this->assertNotContains('glycemic_index', $this->missingOf($food));
    }

    public function test_the_index_filter_returns_the_right_rows(): void
    {
        $needsGi = $this->food(['name_en' => 'Aa needs gi', 'carbs_g_per_100g' => 30, 'sodium_mg_per_100g' => 1, 'cost_tier' => 'standard', 'prep_level' => 'easy']);
        $meat = $this->food(['name_en' => 'Bb meat', 'carbs_g_per_100g' => 0, 'sodium_mg_per_100g' => 1, 'cost_tier' => 'standard', 'prep_level' => 'easy']);
        $needsSodium = $this->food(['name_en' => 'Cc needs sodium', 'carbs_g_per_100g' => 30, 'glycemic_index' => 40, 'cost_tier' => 'standard', 'prep_level' => 'easy']);
        $needsTag = $this->food(['name_en' => 'Dd needs tag', 'carbs_g_per_100g' => 30, 'glycemic_index' => 40, 'sodium_mg_per_100g' => 1, 'cost_tier' => 'standard']);
        $complete = $this->food(['name_en' => 'Ee complete', 'carbs_g_per_100g' => 30, 'glycemic_index' => 40, 'sodium_mg_per_100g' => 1, 'cost_tier' => 'standard', 'prep_level' => 'easy']);

        $ids = fn (string $missing) => collect($this->getJson("/api/v1/admin/foods?missing={$missing}", $this->adminToken)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$needsGi->id], $ids('glycemic_index'), 'meat (no carbs) is not asked for a glycemic index');
        $this->assertSame([$needsSodium->id], $ids('sodium'));
        $this->assertSame([$needsTag->id], $ids('tags'));
        $this->assertCount(5, $this->getJson('/api/v1/admin/foods', $this->adminToken)->json('data'), 'no filter: everything');
        $this->assertNotContains($complete->id, array_merge($ids('glycemic_index'), $ids('sodium'), $ids('tags')));
        $this->assertNotContains($meat->id, $ids('glycemic_index'));

        $this->getJson('/api/v1/admin/foods?missing=colour', $this->adminToken)->assertStatus(422);
    }

    public function test_the_filter_combines_with_status_and_search_and_paginates(): void
    {
        foreach (range(1, 27) as $i) {
            $this->food(['name_en' => "Pager {$i}", 'carbs_g_per_100g' => 30]);
        }
        $this->food(['name_en' => 'Pager pending', 'carbs_g_per_100g' => 30, 'status' => 'pending']);

        $page = $this->getJson('/api/v1/admin/foods?missing=glycemic_index&status=approved', $this->adminToken)->assertOk();
        $this->assertCount(25, $page->json('data'));
        $this->assertSame(27, $page->json('meta.total'));

        $this->assertSame(1, $this->getJson('/api/v1/admin/foods?missing=sodium&status=pending&q=Pager', $this->adminToken)->json('meta.total'));
    }

    public function test_the_pending_queue_carries_the_new_fields(): void
    {
        $this->submit(['glycemic_index' => 73, 'sodium_mg_per_100g' => 2, 'nutrition_review_source' => 'T'])->assertCreated();

        $row = $this->getJson('/api/v1/foods/pending', $this->adminToken)->assertOk()->json('data.0');

        $this->assertEquals([73, 2.0, 'T', null, ['cost_tier', 'prep_level']], [$row['glycemic_index'], $row['sodium_mg_per_100g'], $row['nutrition_review_source'], $row['nutrition_reviewed_at'], $row['missing']]);
    }

    // ---- patient payloads ---------------------------------------------------------------------------------------------

    public function test_the_patient_never_gets_the_review_fields(): void
    {
        $food = Food::factory()->create(['carbs_g_per_100g' => 30, 'cost_tier' => 'economical']);
        $food->forceFill(['glycemic_index' => 73, 'sodium_mg_per_100g' => 5, 'nutrition_review_source' => 'Secret table', 'nutrition_reviewed_by' => $this->admin->id, 'nutrition_reviewed_at' => now()])->save();
        $client = User::factory()->client($this->nutritionist)->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id]);
        $plan = MealPlan::create(['subscriber_id' => $subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'active', 'activated_at' => now()]);
        $meal = Meal::create(['meal_plan_id' => $plan->id, 'name' => 'lunch', 'sort_order' => 0]);
        $item = MealItem::create(['meal_id' => $meal->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => 0]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'meal_item_id' => $item->id, 'meal_type' => 'lunch', 'quantity_grams' => 100, 'logged_at' => now()]);
        $token = $this->bearerFor($client);

        $bodies = [
            $this->getJson('/api/v1/me/meal-plan', $token)->assertOk()->json('meals.0.items.0.food'),
            $this->getJson('/api/v1/me/meal-logs', $token)->assertOk()->json('data.0.food'),
        ];

        foreach ($bodies as $foodPayload) {
            foreach (['glycemic_index', 'sodium_mg_per_100g', 'nutrition_review_source', 'nutrition_reviewed_at', 'nutrition_reviewer_name', 'missing'] as $hidden) {
                $this->assertArrayNotHasKey($hidden, $foodPayload, $hidden);
            }
            $this->assertSame('economical', $foodPayload['cost_tier'], 'the Phase 4 tags are unchanged');
        }
    }

    public function test_the_patient_food_search_does_not_carry_them_either(): void
    {
        Food::factory()->create(['name_en' => 'Searchable']);
        $client = User::factory()->client($this->nutritionist)->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id]);

        $row = $this->getJson('/api/v1/foods/search?q=Searchable', $this->bearerFor($client))->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('missing', $row);
        $this->assertArrayNotHasKey('glycemic_index', $row);
    }

    public function test_the_nutritionist_search_does_carry_them(): void
    {
        Food::factory()->create(['name_en' => 'Searchable', 'carbs_g_per_100g' => 30]);

        $row = $this->getJson('/api/v1/foods/search?q=Searchable', $this->nutritionistToken)->assertOk()->json('data.0');

        $this->assertSame('glycemic_index', $row['missing'][0]);
    }
}
