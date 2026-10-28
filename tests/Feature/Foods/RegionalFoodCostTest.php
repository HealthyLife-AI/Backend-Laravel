<?php

namespace Tests\Feature\Foods;

use App\Models\Food;
use App\Models\FoodRegionalCost;
use App\Models\HealthProfile;
use App\Models\Meal;
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

/**
 * A food's cost tier is regional: foods.cost_tier is the PS value, a food_regional_costs row is
 * another country's (or a PS override), and another country never inherits the PS value.
 */
class RegionalFoodCostTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $admin;

    private User $ps;

    private User $ye;

    private User $psPatient;

    private User $yePatient;

    private Subscriber $yeSubscriber;

    private Subscriber $psSubscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->admin()->create();
        $this->ps = User::factory()->nutritionist()->create();
        $this->ye = User::factory()->nutritionist()->create(['country' => 'YE']);

        $this->psPatient = User::factory()->client($this->ps)->create();
        $this->yePatient = User::factory()->client($this->ye)->create();
        $this->psSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->ps->id, 'user_id' => $this->psPatient->id]);
        $this->yeSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->ye->id, 'user_id' => $this->yePatient->id]);
    }

    private function tokenOf(User $user): array
    {
        return $this->bearerFor($user);
    }

    private function food(array $attributes = []): Food
    {
        return Food::factory()->create($attributes + ['allergens' => []]);
    }

    private function regional(Food $food, string $country, string $tier): FoodRegionalCost
    {
        return FoodRegionalCost::create(['food_id' => $food->id, 'country' => $country, 'cost_tier' => $tier]);
    }

    private function searchTiers(User $viewer, string $q): array
    {
        return collect($this->getJson('/api/v1/foods/search?q='.urlencode($q), $this->tokenOf($viewer))->assertOk()->json('data'))->pluck('cost_tier', 'name_en')->all();
    }

    // ---- the resolver -------------------------------------------------------------------------------

    public function test_a_ps_nutritionist_sees_the_base_value_a_ye_one_sees_only_their_own_rows(): void
    {
        $food = $this->food(['name_en' => 'Zed lentils', 'cost_tier' => 'economical']);

        $this->assertSame('economical', $food->costTierFor('PS'));
        $this->assertNull($food->costTierFor('YE'), 'never the PS value for another country');

        $this->regional($food, 'YE', 'premium');
        $this->assertSame('premium', $food->fresh()->costTierFor('YE'));
        $this->assertSame('economical', $food->fresh()->costTierFor('PS'), 'a YE row does not touch PS');
    }

    public function test_a_regional_ps_row_overrides_the_base_value(): void
    {
        $food = $this->food(['cost_tier' => 'economical']);
        $this->regional($food, 'PS', 'premium');

        $this->assertSame('premium', $food->fresh()->costTierFor('PS'));
        $this->assertSame('economical', $food->fresh()->cost_tier, 'foods.cost_tier itself is untouched');
    }

    public function test_the_pure_rule(): void
    {
        $this->assertSame('standard', Food::resolveCostTier('PS', 'standard', 'economical'));
        $this->assertSame('economical', Food::resolveCostTier('PS', null, 'economical'));
        $this->assertNull(Food::resolveCostTier('YE', null, 'economical'));
        $this->assertSame('premium', Food::resolveCostTier('YE', 'premium', 'economical'));
        $this->assertNull(Food::resolveCostTier('YE', null, null));
    }

    public function test_each_viewer_sees_their_nutritionists_country_in_the_food_payload(): void
    {
        $this->food(['name_en' => 'Zed rice', 'cost_tier' => 'economical']);
        $yeOnly = $this->food(['name_en' => 'Zed dates']);
        $this->regional($yeOnly, 'YE', 'standard');

        $this->assertSame(['Zed rice' => 'economical', 'Zed dates' => null], $this->searchTiers($this->ps, 'Zed'));
        $this->assertSame(['Zed rice' => null, 'Zed dates' => 'standard'], $this->searchTiers($this->ye, 'Zed'));
        // A patient reads their nutritionist's country.
        $this->assertSame(['Zed rice' => 'economical', 'Zed dates' => null], $this->searchTiers($this->psPatient, 'Zed'));
        $this->assertSame(['Zed rice' => null, 'Zed dates' => 'standard'], $this->searchTiers($this->yePatient, 'Zed'));
    }

    public function test_the_admin_sees_the_base_value_itself_and_the_regional_rows_beside_it(): void
    {
        $food = $this->food(['name_en' => 'Zed beans', 'cost_tier' => 'economical']);
        $this->regional($food, 'PS', 'premium');
        $this->regional($food, 'YE', 'standard');

        $row = $this->getJson('/api/v1/admin/foods?q=Zed', $this->tokenOf($this->admin))->assertOk()->json('data.0');

        $this->assertSame('economical', $row['cost_tier'], 'editing it never copies an override into foods.cost_tier');
        $this->assertSame([['PS', 'premium'], ['YE', 'standard']], array_map(fn ($r) => [$r['country'], $r['cost_tier']], $row['regional_costs']));
        $this->assertArrayHasKey('set_by_name', $row['regional_costs'][0]);
    }

    public function test_the_missing_cost_follows_the_viewers_country(): void
    {
        $food = $this->food(['name_en' => 'Zed millet', 'carbs_g_per_100g' => 0, 'cost_tier' => 'economical', 'prep_level' => 'easy', 'sodium_mg_per_100g' => 1]);

        $this->assertSame([], $this->getJson('/api/v1/foods/search?q=Zed', $this->tokenOf($this->ps))->json('data.0.missing'));
        $this->assertSame(['cost_tier'], $this->getJson('/api/v1/foods/search?q=Zed', $this->tokenOf($this->ye))->json('data.0.missing'));
        $this->assertNotNull($food);
    }

    // ---- AI draft ---------------------------------------------------------------------------------------

    private function draftPayload(User $nutritionist, Subscriber $subscriber): array
    {
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'm']);
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        Http::fake(['*' => Http::response(null, 500)]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", ['prefer_economical' => true], $this->tokenOf($nutritionist))->assertSuccessful();

        $payload = null;
        Http::assertSent(function ($request) use (&$payload): bool {
            $payload ??= json_decode($request['messages'][1]['content'], true);

            return true;
        });

        return $payload;
    }

    public function test_the_draft_for_a_ye_nutritionist_does_not_treat_ps_economical_foods_as_economical(): void
    {
        $psCheap = $this->food(['name_en' => 'Aaa ps cheap', 'cost_tier' => 'economical']);
        $yeCheap = $this->food(['name_en' => 'Zzz ye cheap']);
        $this->regional($yeCheap, 'YE', 'economical');
        $this->food(['name_en' => 'Mmm unknown']);

        $payload = $this->draftPayload($this->ye, $this->yeSubscriber);

        $column = array_search('cost_tier', $payload['food_columns'], true);
        $rows = collect($payload['available_foods'])->keyBy(fn ($r) => $r[1]);

        $this->assertNull($rows['Aaa ps cheap'][$column], 'the PS value is not YE\'s');
        $this->assertSame('economical', $rows['Zzz ye cheap'][$column]);
        $this->assertNull($rows['Mmm unknown'][$column]);
        $this->assertSame('Zzz ye cheap', $payload['available_foods'][0][1], 'the YE-economical food leads the menu');
        $this->assertNotNull($psCheap);
    }

    public function test_the_draft_for_a_ps_nutritionist_still_uses_the_base_value_and_a_ps_override(): void
    {
        $base = $this->food(['name_en' => 'Aaa base cheap', 'cost_tier' => 'economical']);
        $overridden = $this->food(['name_en' => 'Bbb overridden', 'cost_tier' => 'economical']);
        $this->regional($overridden, 'PS', 'premium');

        $payload = $this->draftPayload($this->ps, $this->psSubscriber);

        $column = array_search('cost_tier', $payload['food_columns'], true);
        $rows = collect($payload['available_foods'])->keyBy(fn ($r) => $r[1]);
        $this->assertSame('economical', $rows['Aaa base cheap'][$column]);
        $this->assertSame('premium', $rows['Bbb overridden'][$column]);
        $this->assertNotNull($base);
    }

    // ---- the economical search filter ----------------------------------------------------------------------------

    public function test_the_economical_filter_for_a_ye_patient_returns_only_ye_economical_foods_and_paginates(): void
    {
        foreach (range(1, 5) as $i) {
            $this->regional($this->food(['name_en' => "Zed ye {$i}"]), 'YE', 'economical');
        }
        $this->regional($this->food(['name_en' => 'Zed ye standard']), 'YE', 'standard');
        $this->food(['name_en' => 'Zed ps only', 'cost_tier' => 'economical']);
        $this->food(['name_en' => 'Zed nothing']);
        $token = $this->tokenOf($this->yePatient);

        $page1 = $this->getJson('/api/v1/foods/search?q=Zed&economical=1&per_page=2', $token)->assertOk();
        $page3 = $this->getJson('/api/v1/foods/search?q=Zed&economical=1&per_page=2&page=3', $token)->assertOk();

        $this->assertSame(5, $page1->json('meta.total'), 'counted in the query, not after it');
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertCount(2, $page1->json('data'));
        $this->assertCount(1, $page3->json('data'));
        foreach ($page1->json('data') as $row) {
            $this->assertSame('economical', $row['cost_tier']);
        }
        $this->assertStringNotContainsString('ps only', json_encode($page1->json()));
    }

    public function test_the_economical_filter_for_ps_uses_the_base_value_and_a_ps_override(): void
    {
        $this->food(['name_en' => 'Zed base cheap', 'cost_tier' => 'economical']);
        $overridden = $this->food(['name_en' => 'Zed overridden', 'cost_tier' => 'economical']);
        $this->regional($overridden, 'PS', 'premium');
        $promoted = $this->food(['name_en' => 'Zed promoted', 'cost_tier' => 'premium']);
        $this->regional($promoted, 'PS', 'economical');
        $this->regional($this->food(['name_en' => 'Zed ye cheap']), 'YE', 'economical');

        $names = collect($this->getJson('/api/v1/foods/search?q=Zed&economical=1', $this->tokenOf($this->psPatient))->json('data'))->pluck('name_en')->sort()->values()->all();

        $this->assertSame(['Zed base cheap', 'Zed promoted'], $names);
    }

    // ---- writing a cost -----------------------------------------------------------------------------------------------

    public function test_a_nutritionist_sets_and_clears_the_cost_for_their_own_country_only(): void
    {
        $food = $this->food(['name_en' => 'Zed tea', 'cost_tier' => 'economical']);
        $token = $this->tokenOf($this->ye);

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'premium'], $token)->assertOk()
            ->assertExactJson(['food_id' => $food->id, 'country' => 'YE', 'cost_tier' => 'premium']);

        $row = FoodRegionalCost::first();
        $this->assertSame(['YE', 'premium', $this->ye->id], [$row->country, $row->cost_tier, $row->set_by]);
        $this->assertSame('economical', $food->fresh()->cost_tier, 'the PS value is untouched');
        $this->assertSame('premium', $this->searchTiers($this->ye, 'Zed')['Zed tea'], 'effective at once');

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'standard'], $token)->assertOk();
        $this->assertSame(1, FoodRegionalCost::count(), 'one row per food and country');

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => null], $token)->assertOk()->assertJsonPath('cost_tier', null);
        $this->assertSame(0, FoodRegionalCost::count());
    }

    public function test_a_country_in_the_request_is_refused(): void
    {
        $food = $this->food();

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'economical', 'country' => 'PS'], $this->tokenOf($this->ye))
            ->assertStatus(422)->assertJsonValidationErrors('country');
        $this->assertSame(0, FoodRegionalCost::count());
    }

    public function test_the_cost_rules(): void
    {
        $food = $this->food();
        $token = $this->tokenOf($this->ye);

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'cheap'], $token)->assertStatus(422);
        $this->putJson("/api/v1/foods/{$food->id}/cost", [], $token)->assertStatus(422);
        $this->putJson('/api/v1/foods/99999/cost', ['cost_tier' => 'economical'], $token)->assertNotFound();
        $food->forceFill(['status' => 'rejected'])->save();
        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'economical'], $token)->assertNotFound();
    }

    public function test_a_patient_and_an_admin_cannot_use_the_nutritionist_endpoint(): void
    {
        $food = $this->food();

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'economical'], $this->tokenOf($this->yePatient))->assertForbidden();
        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'economical'], $this->tokenOf($this->admin))->assertForbidden();
        $this->assertSame(0, FoodRegionalCost::count());
    }

    public function test_a_ps_nutritionist_writes_a_regional_ps_row_that_overrides_the_base(): void
    {
        $food = $this->food(['name_en' => 'Zed oil', 'cost_tier' => 'economical']);

        $this->putJson("/api/v1/foods/{$food->id}/cost", ['cost_tier' => 'premium'], $this->tokenOf($this->ps))->assertOk()->assertJsonPath('country', 'PS');

        $this->assertSame('premium', $this->searchTiers($this->ps, 'Zed')['Zed oil']);
        $this->assertSame('economical', $food->fresh()->cost_tier);
    }

    // ---- submission ----------------------------------------------------------------------------------------------------------------

    private function submitBody(array $extra = []): array
    {
        return $extra + ['name_ar' => 'عدس', 'name_en' => 'Lentils', 'calories_per_100g' => 116, 'protein_g_per_100g' => 9, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 0.4];
    }

    public function test_a_ye_submission_puts_its_cost_in_the_regional_table(): void
    {
        $id = $this->postJson('/api/v1/foods', $this->submitBody(['cost_tier' => 'economical', 'prep_level' => 'easy']), $this->tokenOf($this->ye))->assertCreated()->json('id');

        $food = Food::find($id);
        $this->assertNull($food->cost_tier, 'foods.cost_tier is the PS value');
        $this->assertSame('easy', $food->prep_level, 'prep is global');
        $row = FoodRegionalCost::where('food_id', $id)->first();
        $this->assertSame(['YE', 'economical', $this->ye->id], [$row->country, $row->cost_tier, $row->set_by]);
    }

    public function test_a_ps_submission_keeps_its_cost_on_the_food(): void
    {
        $id = $this->postJson('/api/v1/foods', $this->submitBody(['cost_tier' => 'economical']), $this->tokenOf($this->ps))->assertCreated()->json('id');

        $this->assertSame('economical', Food::find($id)->cost_tier);
        $this->assertSame(0, FoodRegionalCost::count());
    }

    public function test_a_submission_without_a_cost_writes_no_row(): void
    {
        $this->postJson('/api/v1/foods', $this->submitBody(), $this->tokenOf($this->ye))->assertCreated();

        $this->assertSame(0, FoodRegionalCost::count());
    }

    // ---- admin ----------------------------------------------------------------------------------------------------------------------------

    public function test_the_admin_sets_and_clears_a_regional_cost_for_any_country(): void
    {
        $food = $this->food(['name_en' => 'Zed salt']);
        $token = $this->tokenOf($this->admin);

        $this->putJson("/api/v1/admin/foods/{$food->id}/regional-costs/YE", ['cost_tier' => 'standard'], $token)->assertOk()
            ->assertJsonPath('regional_costs.0.country', 'YE')->assertJsonPath('regional_costs.0.cost_tier', 'standard')
            ->assertJsonPath('regional_costs.0.set_by_name', $this->admin->name);

        $this->putJson("/api/v1/admin/foods/{$food->id}/regional-costs/YE", ['cost_tier' => null], $token)->assertOk()->assertJsonPath('regional_costs', []);
        $this->putJson("/api/v1/admin/foods/{$food->id}/regional-costs/XX", ['cost_tier' => 'standard'], $token)->assertNotFound();
        $this->putJson("/api/v1/admin/foods/{$food->id}/regional-costs/YE", ['cost_tier' => 'cheap'], $token)->assertStatus(422);
        $this->putJson("/api/v1/admin/foods/{$food->id}/regional-costs/YE", ['cost_tier' => 'standard'], $this->tokenOf($this->ye))->assertForbidden();
    }

    public function test_the_admin_edit_still_sets_the_base_value_only(): void
    {
        $food = $this->food(['name_en' => 'Zed edit']);
        $this->regional($food, 'YE', 'premium');

        $this->putJson("/api/v1/admin/foods/{$food->id}", $this->submitBody(['name_en' => 'Zed edit', 'cost_tier' => 'economical']), $this->tokenOf($this->admin))->assertOk()->assertJsonPath('cost_tier', 'economical');

        $this->assertSame('economical', $food->fresh()->cost_tier);
        $this->assertSame('premium', FoodRegionalCost::first()->cost_tier, 'the YE row is not touched');
    }

    public function test_the_missing_tags_filter_can_be_asked_for_a_country(): void
    {
        $psTagged = $this->food(['name_en' => 'Aa ps tagged', 'cost_tier' => 'economical', 'prep_level' => 'easy']);
        $yeTagged = $this->food(['name_en' => 'Bb ye tagged', 'prep_level' => 'easy']);
        $this->regional($yeTagged, 'YE', 'standard');
        $bothMissing = $this->food(['name_en' => 'Cc nothing', 'prep_level' => 'easy']);
        $noPrep = $this->food(['name_en' => 'Dd no prep', 'cost_tier' => 'economical']);
        $this->regional($noPrep, 'YE', 'economical');
        $token = $this->tokenOf($this->admin);
        $names = fn (string $qs) => collect($this->getJson("/api/v1/admin/foods?missing=tags{$qs}", $token)->assertOk()->json('data'))->pluck('name_en')->sort()->values()->all();

        $this->assertSame(['Bb ye tagged', 'Cc nothing', 'Dd no prep'], $names(''), 'default: no PS value (foods.cost_tier), or no prep');
        $this->assertSame(['Aa ps tagged', 'Cc nothing', 'Dd no prep'], $names('&country=YE'), 'YE: no YE row, or no prep');
        $this->assertSame(['Bb ye tagged', 'Cc nothing', 'Dd no prep'], $names('&country=PS'));
        $this->getJson('/api/v1/admin/foods?missing=tags&country=XX', $token)->assertStatus(422);
        $this->assertNotNull($psTagged);
        $this->assertNotNull($bothMissing);
    }

    // ---- the nutritionist's country ---------------------------------------------------------------------------------------------------------

    public function test_existing_and_new_nutritionists_default_to_ps(): void
    {
        $this->assertSame('PS', DB::table('users')->where('id', $this->ps->id)->value('country'));
        $this->assertSame('PS', DB::table('users')->where('id', $this->admin->id)->value('country'));
        $this->assertSame('YE', DB::table('users')->where('id', $this->ye->id)->value('country'));
    }

    public function test_the_admin_sets_a_nutritionists_country(): void
    {
        $this->putJson("/api/v1/admin/nutritionists/{$this->ps->id}/country", ['country' => 'YE'], $this->tokenOf($this->admin))->assertOk()
            ->assertExactJson(['id' => $this->ps->id, 'country' => 'YE']);

        $this->assertSame('YE', $this->ps->fresh()->country);
        $list = $this->getJson('/api/v1/admin/nutritionists', $this->tokenOf($this->admin))->assertOk();
        $this->assertSame(['PS' => 'فلسطين', 'YE' => 'اليمن'], $list->json('countries'));
        $this->assertSame('YE', collect($list->json('data'))->firstWhere('id', $this->ps->id)['country']);
    }

    public function test_the_country_rules(): void
    {
        $token = $this->tokenOf($this->admin);

        $this->putJson("/api/v1/admin/nutritionists/{$this->ps->id}/country", ['country' => 'XX'], $token)->assertStatus(422)->assertJsonValidationErrors('country');
        $this->putJson("/api/v1/admin/nutritionists/{$this->ps->id}/country", ['country' => 'ye'], $token)->assertStatus(422);
        $this->putJson("/api/v1/admin/nutritionists/{$this->ps->id}/country", [], $token)->assertStatus(422);
        $this->putJson("/api/v1/admin/nutritionists/{$this->psPatient->id}/country", ['country' => 'YE'], $token)->assertNotFound();
        $this->assertSame('PS', $this->ps->fresh()->country);
    }

    public function test_a_nutritionist_sees_their_country_but_cannot_change_it(): void
    {
        $token = $this->tokenOf($this->ye);

        $this->putJson("/api/v1/admin/nutritionists/{$this->ye->id}/country", ['country' => 'PS'], $token)->assertForbidden();
        $this->putJson('/api/v1/me/nutritionist-profile', ['clinic_name' => 'Clinic', 'country' => 'PS'], $token);
        $this->putJson('/api/v1/me/nutritionist-profile', ['country' => 'PS'], $token);

        $this->assertSame('YE', $this->ye->fresh()->country);
        $this->getJson('/api/v1/auth/me', $token)->assertOk()->assertJsonPath('country', 'YE');
    }

    public function test_a_patient_payload_has_no_country(): void
    {
        $this->getJson('/api/v1/auth/me', $this->tokenOf($this->yePatient))->assertOk()->assertJsonMissingPath('country');
    }

    // ---- plans -------------------------------------------------------------------------------------------------------------------------------------

    private function activePlan(Subscriber $subscriber, User $nutritionist, array $foods): void
    {
        $plan = MealPlan::create(['subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id, 'status' => 'active', 'activated_at' => now()]);
        $meal = Meal::create(['meal_plan_id' => $plan->id, 'name' => 'lunch', 'sort_order' => 0]);

        foreach ($foods as $i => $food) {
            MealItem::create(['meal_id' => $meal->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => $i]);
        }
    }

    public function test_a_meal_item_in_a_ye_plan_shows_the_ye_tier_or_null(): void
    {
        $psCheap = $this->food(['cost_tier' => 'economical']);
        $yeCheap = $this->food();
        $this->regional($yeCheap, 'YE', 'standard');
        $this->activePlan($this->yeSubscriber, $this->ye, [$psCheap, $yeCheap]);
        $this->activePlan($this->psSubscriber, $this->ps, [$psCheap, $yeCheap]);

        $items = fn (User $patient) => collect($this->getJson('/api/v1/me/meal-plan', $this->tokenOf($patient))->assertOk()->json('meals.0.items'));

        $this->assertSame([null, 'standard'], $items($this->yePatient)->pluck('cost_tier')->all());
        $this->assertSame([null, 'standard'], $items($this->yePatient)->pluck('food.cost_tier')->all());
        $this->assertSame(['economical', null], $items($this->psPatient)->pluck('cost_tier')->all());
    }

    public function test_the_nutritionists_plan_view_resolves_for_their_own_country(): void
    {
        $food = $this->food();
        $this->regional($food, 'YE', 'premium');
        $this->activePlan($this->yeSubscriber, $this->ye, [$food]);
        $planId = MealPlan::where('subscriber_id', $this->yeSubscriber->id)->value('id');

        $this->getJson("/api/v1/clients/{$this->yeSubscriber->id}/meal-plans/{$planId}", $this->tokenOf($this->ye))->assertOk()
            ->assertJsonPath('meals.0.items.0.cost_tier', 'premium');
    }

    public function test_the_plan_view_costs_the_same_number_of_queries_for_2_items_or_20(): void
    {
        $count = function (int $items) {
            $foods = collect(range(1, $items))->map(fn ($i) => $this->food(['name_en' => "N+1 {$items}-{$i}"]));
            foreach ($foods as $i => $food) {
                if ($i % 2 === 0) {
                    $this->regional($food, 'YE', 'economical');
                }
            }
            $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->ye->id, 'user_id' => ($patient = User::factory()->client($this->ye)->create())->id]);
            $this->activePlan($subscriber, $this->ye, $foods->all());
            $token = $this->tokenOf($patient);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/meal-plan', $token)->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count($log);
        };

        // The first request of a test warms the permission cache (a fixed cost); count from the second on.
        $this->getJson('/api/v1/me/meal-plan', $this->tokenOf($this->yePatient));

        $small = $count(2);
        $large = $count(20);

        $this->assertSame($small, $large, 'the regional rows are eager-loaded for the whole plan, not asked food by food');
    }

    public function test_the_search_costs_the_same_number_of_queries_for_a_big_page(): void
    {
        foreach (range(1, 20) as $i) {
            $this->regional($this->food(['name_en' => "Page {$i}"]), 'YE', 'economical');
        }
        $token = $this->tokenOf($this->yePatient);
        $queries = function (int $perPage) use ($token) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/foods/search?q=Page&per_page={$perPage}", $token)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($queries(2), $queries(20));
    }

    // ---- unchanged behaviour --------------------------------------------------------------------------------------------------------------------------

    public function test_the_other_tags_stay_global(): void
    {
        $this->food(['name_en' => 'Zed global', 'prep_level' => 'no_cook', 'glycemic_index' => 30]);

        $ps = $this->getJson('/api/v1/foods/search?q=Zed', $this->tokenOf($this->ps))->json('data.0');
        $ye = $this->getJson('/api/v1/foods/search?q=Zed', $this->tokenOf($this->ye))->json('data.0');

        $this->assertSame([$ps['prep_level'], $ps['glycemic_index']], [$ye['prep_level'], $ye['glycemic_index']]);
        $this->assertSame('no_cook', $ye['prep_level']);
    }

    public function test_deleting_a_food_removes_its_regional_rows(): void
    {
        $food = $this->food();
        $this->regional($food, 'YE', 'economical');

        $this->deleteJson("/api/v1/admin/foods/{$food->id}", [], $this->tokenOf($this->admin))->assertNoContent();

        $this->assertSame(0, FoodRegionalCost::count());
    }
}
