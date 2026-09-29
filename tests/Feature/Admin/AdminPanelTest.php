<?php

namespace Tests\Feature\Admin;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsdaFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Admin panel API, client deletion, and the shipped food catalog. */
class AdminPanelTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create())->assignRole('admin');
    }

    public function test_admin_overview_counts_the_whole_platform(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        Subscriber::factory()->active()->count(2)->create(['nutritionist_id' => $nutritionist->id]);
        Food::factory()->create(['status' => 'pending', 'source' => 'nutritionist']);

        $this->getJson('/api/v1/admin/overview', $this->bearerFor($this->admin()))
            ->assertOk()
            // The subscriber factory makes its own throwaway nutritionist per row.
            ->assertJsonPath('nutritionists', User::role('nutritionist')->count())
            ->assertJsonPath('clients', 2)
            ->assertJsonPath('active_clients', 2)
            ->assertJsonPath('foods_pending', 1);
    }

    public function test_admin_lists_nutritionists_with_client_counts(): void
    {
        $nutritionist = User::factory()->nutritionist()->create(['name' => 'Dr. Amal']);
        Subscriber::factory()->active()->count(3)->create(['nutritionist_id' => $nutritionist->id]);

        $rows = $this->getJson('/api/v1/admin/nutritionists', $this->bearerFor($this->admin()))
            ->assertOk()
            ->json('data');

        // The subscriber factory also makes a throwaway nutritionist per
        // row, created in the same second, so find ours rather than
        // relying on where it lands in the list.
        $row = collect($rows)->firstWhere('id', $nutritionist->id);
        $this->assertSame('Dr. Amal', $row['name']);
        $this->assertSame(3, $row['clients_count']);
    }

    public function test_nutritionists_cannot_reach_the_admin_api(): void
    {
        $auth = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->getJson('/api/v1/admin/overview', $auth)->assertForbidden();
        $this->getJson('/api/v1/admin/foods', $auth)->assertForbidden();
        $this->postJson('/api/v1/admin/foods', ['name_ar' => 'x', 'calories_per_100g' => 1, 'protein_g_per_100g' => 1, 'carbs_g_per_100g' => 1, 'fat_g_per_100g' => 1], $auth)->assertForbidden();
    }

    public function test_admin_added_food_is_approved_and_searchable_immediately(): void
    {
        $this->postJson('/api/v1/admin/foods', [
            'name_ar' => 'مقلوبة دجاج', 'name_en' => 'Chicken Maqluba',
            'calories_per_100g' => 170, 'protein_g_per_100g' => 9, 'carbs_g_per_100g' => 19, 'fat_g_per_100g' => 6.5,
        ], $this->bearerFor($this->admin()))->assertCreated()->assertJsonPath('status', 'approved');

        $this->getJson('/api/v1/foods/search?q='.urlencode('مقلوبة'), $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_edit_a_food(): void
    {
        $food = Food::factory()->create(['status' => 'approved', 'calories_per_100g' => 100]);

        $this->putJson("/api/v1/admin/foods/{$food->id}", [
            'name_en' => 'Corrected', 'calories_per_100g' => 120, 'protein_g_per_100g' => 3, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 2,
        ], $this->bearerFor($this->admin()))->assertOk()->assertJsonPath('calories_per_100g', 120);
    }

    public function test_admin_cannot_delete_a_food_used_in_a_plan(): void
    {
        $food = Food::factory()->create(['status' => 'approved']);
        $unused = Food::factory()->create(['status' => 'approved']);
        $nutritionist = User::factory()->nutritionist()->create();
        $planId = DB::table('meal_plans')->insertGetId(['created_by' => $nutritionist->id, 'status' => 'draft', 'is_template' => true, 'created_at' => now(), 'updated_at' => now()]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('meal_items')->insert(['meal_id' => $mealId, 'food_id' => $food->id, 'quantity_grams' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $auth = $this->bearerFor($this->admin());
        $this->deleteJson("/api/v1/admin/foods/{$food->id}", [], $auth)->assertStatus(409)->assertJsonPath('code', 'food_in_use');
        $this->deleteJson("/api/v1/admin/foods/{$unused->id}", [], $auth)->assertNoContent();
        $this->assertModelMissing($unused);
    }

    public function test_nutritionist_sees_their_own_submissions_with_status(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $auth = $this->bearerFor($nutritionist);

        $this->postJson('/api/v1/foods', ['name_ar' => 'كبة لبنية', 'calories_per_100g' => 150, 'protein_g_per_100g' => 8, 'carbs_g_per_100g' => 12, 'fat_g_per_100g' => 7], $auth)->assertCreated();
        Food::factory()->create(['status' => 'pending', 'source' => 'nutritionist']); // someone else's

        $this->getJson('/api/v1/foods/mine', $auth)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_nutritionist_deletes_their_client_and_everything_about_them(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $client->alerts()->create(['type' => 'no_log', 'message' => 'x']);
        $clientUserId = $client->user_id;

        $this->deleteJson("/api/v1/clients/{$client->id}", [], $this->bearerFor($nutritionist))->assertNoContent();

        $this->assertModelMissing($client);
        $this->assertDatabaseMissing('users', ['id' => $clientUserId]);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_nutritionist_cannot_delete_another_nutritionists_client(): void
    {
        $client = Subscriber::factory()->active()->create();

        $this->deleteJson("/api/v1/clients/{$client->id}", [], $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertNotFound();

        $this->assertModelExists($client);
    }

    public function test_the_shipped_usda_catalog_seeds_once_with_arabic_names(): void
    {
        $this->seed(UsdaFoodSeeder::class);
        $count = Food::where('source', 'usda')->count();

        $this->assertGreaterThan(7000, $count);
        $this->assertSame('موز طازج', Food::where('name_en', 'Bananas, raw')->value('name_ar'));

        // Re-seeding adds nothing and keeps an admin's edit.
        Food::where('name_en', 'Bananas, raw')->update(['name_ar' => 'موز بلدي']);
        $this->seed(UsdaFoodSeeder::class);

        $this->assertSame($count, Food::where('source', 'usda')->count());
        $this->assertSame('موز بلدي', Food::where('name_en', 'Bananas, raw')->value('name_ar'));
    }

    public function test_search_finds_a_term_inside_a_name_and_ranks_prefix_matches_first(): void
    {
        Food::factory()->create(['name_ar' => 'صدر دجاج مشوي', 'name_en' => null, 'status' => 'approved']);
        Food::factory()->create(['name_ar' => 'دجاج كامل', 'name_en' => null, 'status' => 'approved']);

        $this->getJson('/api/v1/foods/search?q='.urlencode('دجاج'), $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name_ar', 'دجاج كامل');
    }
}
