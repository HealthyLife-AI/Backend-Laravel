<?php

namespace Tests\Feature\Admin;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsdaFoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Pre-release checks on the catalog, the admin API and patient deletion:
 * demo-account gating, USDA deletes that survive a reseed, the in-use
 * edit warning, spelling-insensitive Arabic search, BR-5 in plans and
 * logs, and that deleting a patient removes exactly that patient's data.
 */
class ReleaseSafeguardsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private const DEMO_EMAIL = 'nutritionist@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->setEnv('DEMO_NUTRITIONIST_PASSWORD', null);
        $this->setEnv('ADMIN_EMAIL', null);
        $this->setEnv('ADMIN_PASSWORD', null);
        parent::tearDown();
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /** Runs DatabaseSeeder directly: `db:seed` would stop to confirm under APP_ENV=production. */
    private function runDatabaseSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
    }

    private function admin(): User
    {
        return tap(User::factory()->create())->assignRole('admin');
    }

    // ---- Item 1: demo account -------------------------------------------

    public function test_demo_account_is_not_created_in_production_without_its_password(): void
    {
        $this->app['env'] = 'production';

        $this->runDatabaseSeeder();

        $this->assertDatabaseMissing('users', ['email' => self::DEMO_EMAIL]);
    }

    public function test_demo_account_uses_the_password_from_the_environment(): void
    {
        $this->app['env'] = 'production';
        $this->setEnv('DEMO_NUTRITIONIST_PASSWORD', 'demo-from-env-123');

        $this->runDatabaseSeeder();

        $demo = User::where('email', self::DEMO_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('demo-from-env-123', $demo->password));
        $this->assertFalse(Hash::check('password', $demo->password));
        $this->assertTrue($demo->hasRole('nutritionist'));
    }

    public function test_demo_account_on_local_gets_a_random_password_not_a_shipped_one(): void
    {
        $this->app['env'] = 'local';

        $this->runDatabaseSeeder();

        $demo = User::where('email', self::DEMO_EMAIL)->firstOrFail();
        $this->assertFalse(Hash::check('password', $demo->password));
    }

    // ---- Item 8: seeders twice ------------------------------------------

    public function test_running_all_seeders_twice_adds_no_duplicates_and_keeps_admin_edits(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@test.local');
        $this->setEnv('ADMIN_PASSWORD', 'secret-admin-pass');
        $this->setEnv('DEMO_NUTRITIONIST_PASSWORD', 'demo-pass');

        $this->runDatabaseSeeder();
        $foods = Food::count();
        $users = User::count();

        $banana = Food::where('name_en', 'Bananas, raw')->firstOrFail();
        $banana->update(['name_ar' => 'موز بلدي', 'calories_per_100g' => 90]);
        $hummus = Food::where('source', 'admin')->where('name_en', 'Hummus')->firstOrFail();

        $this->runDatabaseSeeder();

        $this->assertSame($foods, Food::count());
        $this->assertSame($users, User::count());
        $this->assertSame(0, Food::whereNotNull('usda_fdc_id')->select('usda_fdc_id')->groupBy('usda_fdc_id')->havingRaw('COUNT(*) > 1')->count());
        $this->assertSame(1, Food::where('source', 'admin')->where('name_en', 'Hummus')->count());
        $this->assertSame($hummus->id, Food::where('source', 'admin')->where('name_en', 'Hummus')->value('id'));
        $this->assertSame('موز بلدي', $banana->fresh()->name_ar);
        $this->assertEquals(90, $banana->fresh()->calories_per_100g);
        $this->assertTrue(User::where('email', 'admin@test.local')->firstOrFail()->hasRole('admin'));
    }

    // ---- Item 3: USDA deletes stay deleted ------------------------------

    public function test_a_usda_food_the_admin_deletes_stays_gone_after_reseeding(): void
    {
        $this->seed(UsdaFoodSeeder::class);
        $banana = Food::where('name_en', 'Bananas, raw')->firstOrFail();
        $auth = $this->bearerFor($this->admin());
        $nutritionist = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->deleteJson("/api/v1/admin/foods/{$banana->id}", [], $auth)->assertNoContent();
        $this->seed(UsdaFoodSeeder::class);

        $this->assertSame(0, Food::approved()->where('name_en', 'Bananas, raw')->count());
        $this->assertSame(1, Food::where('usda_fdc_id', $banana->usda_fdc_id)->count());
        $this->getJson('/api/v1/foods/search?q=Bananas%2C%20raw', $nutritionist)
            ->assertOk()
            ->assertJsonMissing(['id' => $banana->id]);
    }

    public function test_non_usda_foods_are_still_hard_deleted_and_in_use_foods_refused(): void
    {
        $admin = Food::factory()->create(['source' => 'admin']);
        $usdaInUse = Food::factory()->create(['source' => 'usda', 'usda_fdc_id' => 999001]);
        $this->planUsing($usdaInUse);
        $auth = $this->bearerFor($this->admin());

        $this->deleteJson("/api/v1/admin/foods/{$admin->id}", [], $auth)->assertNoContent();
        $this->assertModelMissing($admin);

        $this->deleteJson("/api/v1/admin/foods/{$usdaInUse->id}", [], $auth)
            ->assertStatus(409)
            ->assertJsonPath('code', 'food_in_use');
        $this->assertSame('approved', $usdaInUse->fresh()->status);
    }

    // ---- Item 4: in-use edit warning ------------------------------------

    public function test_editing_an_in_use_food_needs_confirmation_and_reports_usage(): void
    {
        $food = Food::factory()->create(['calories_per_100g' => 100]);
        $this->planUsing($food);
        $this->planUsing($food);
        $this->logUsing($food);
        $auth = $this->bearerFor($this->admin());
        $payload = ['name_en' => 'Corrected', 'calories_per_100g' => 120, 'protein_g_per_100g' => 3, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 2];

        $this->putJson("/api/v1/admin/foods/{$food->id}", $payload, $auth)
            ->assertStatus(409)
            ->assertJsonPath('code', 'food_in_use_confirm')
            ->assertJsonPath('usage.meal_plans', 2)
            ->assertJsonPath('usage.meal_logs', 1);
        $this->assertEquals(100, $food->fresh()->calories_per_100g);

        $this->putJson("/api/v1/admin/foods/{$food->id}", $payload + ['confirm_in_use' => true], $auth)
            ->assertOk()
            ->assertJsonPath('calories_per_100g', 120);
    }

    // ---- Item 6: Arabic search normalization ----------------------------

    public function test_arabic_search_ignores_hamza_ta_marbuta_and_diacritics(): void
    {
        Food::factory()->create(['name_ar' => 'أرز أبيض مطبوخ', 'name_en' => null]);
        Food::factory()->create(['name_ar' => 'بيضة مسلوقة', 'name_en' => null]);
        Food::factory()->create(['name_ar' => 'مَعْكَرُونَة', 'name_en' => null]);
        $auth = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->getJson('/api/v1/foods/search?q='.urlencode('ارز'), $auth)->assertJsonPath('data.0.name_ar', 'أرز أبيض مطبوخ');
        $this->getJson('/api/v1/foods/search?q='.urlencode('بيضه'), $auth)->assertJsonPath('data.0.name_ar', 'بيضة مسلوقة');
        $this->getJson('/api/v1/foods/search?q='.urlencode('معكرونه'), $auth)->assertJsonPath('data.0.name_ar', 'مَعْكَرُونَة');
    }

    public function test_normalized_search_keeps_the_ranking(): void
    {
        Food::factory()->create(['name_ar' => 'حليب بالأرز', 'name_en' => null]);
        Food::factory()->create(['name_ar' => 'أرز بسمتي طويل الحبة مطبوخ', 'name_en' => null]);
        Food::factory()->create(['name_ar' => 'أرز بني', 'name_en' => null]);
        $auth = $this->bearerFor(User::factory()->nutritionist()->create());

        $names = collect($this->getJson('/api/v1/foods/search?q='.urlencode('ارز'), $auth)->json('data'))->pluck('name_ar');

        // Prefix matches first, shorter first; the mid-word match last.
        $this->assertSame(['أرز بني', 'أرز بسمتي طويل الحبة مطبوخ', 'حليب بالأرز'], $names->all());
    }

    // ---- Item 8: nutritionist is locked out of every admin route --------

    public function test_a_nutritionist_gets_403_on_every_admin_route(): void
    {
        $auth = $this->bearerFor(User::factory()->nutritionist()->create());
        $food = Food::factory()->create();
        $payload = ['name_ar' => 'x', 'calories_per_100g' => 1, 'protein_g_per_100g' => 1, 'carbs_g_per_100g' => 1, 'fat_g_per_100g' => 1];

        $this->getJson('/api/v1/admin/overview', $auth)->assertForbidden();
        $this->getJson('/api/v1/admin/nutritionists', $auth)->assertForbidden();
        $this->getJson('/api/v1/admin/foods', $auth)->assertForbidden();
        $this->postJson('/api/v1/admin/foods', $payload, $auth)->assertForbidden();
        $this->putJson("/api/v1/admin/foods/{$food->id}", $payload + ['confirm_in_use' => true], $auth)->assertForbidden();
        $this->deleteJson("/api/v1/admin/foods/{$food->id}", [], $auth)->assertForbidden();
        $this->assertModelExists($food);
    }

    // ---- Item 8: patient deletion scope ---------------------------------

    public function test_deleting_a_patient_removes_all_their_data_and_nothing_else(): void
    {
        $food = Food::factory()->create();
        $mine = User::factory()->nutritionist()->create();
        $other = User::factory()->nutritionist()->create();
        $target = Subscriber::factory()->active()->create(['nutritionist_id' => $mine->id]);
        $sibling = Subscriber::factory()->active()->create(['nutritionist_id' => $mine->id]);
        $foreign = Subscriber::factory()->active()->create(['nutritionist_id' => $other->id]);
        foreach ([$target, $sibling, $foreign] as $subscriber) {
            $this->fillClientData($subscriber, $food);
        }
        $template = $this->planUsing($food, null, $mine->id);
        $before = [$this->countsFor($sibling), $this->countsFor($foreign)];

        $this->deleteJson("/api/v1/clients/{$target->id}", [], $this->bearerFor($mine))->assertNoContent();

        $this->assertModelMissing($target);
        $this->assertDatabaseMissing('users', ['id' => $target->user_id]);
        $this->assertSame(array_fill_keys(array_keys($this->countsFor($target)), 0), $this->countsFor($target));
        $this->assertSame($before, [$this->countsFor($sibling), $this->countsFor($foreign)]);
        $this->assertDatabaseHas('users', ['id' => $mine->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $template]);
        $this->assertModelExists($food);
    }

    public function test_a_nutritionist_cannot_delete_another_nutritionists_patient_and_its_data_stays(): void
    {
        $food = Food::factory()->create();
        $foreign = Subscriber::factory()->active()->create();
        $this->fillClientData($foreign, $food);
        $before = $this->countsFor($foreign);

        $this->deleteJson("/api/v1/clients/{$foreign->id}", [], $this->bearerFor(User::factory()->nutritionist()->create()))
            ->assertNotFound();

        $this->assertModelExists($foreign);
        $this->assertDatabaseHas('users', ['id' => $foreign->user_id]);
        $this->assertSame($before, $this->countsFor($foreign));
    }

    // ---- Item 8 / BR-5: only approved foods in plans and logs -----------

    public function test_pending_and_rejected_foods_are_refused_in_plans_and_logs(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $approved = Food::factory()->create();
        $auth = $this->bearerFor($nutritionist);
        $clientAuth = $this->bearerFor($subscriber->user);

        foreach (['pending', 'rejected'] as $status) {
            $food = Food::factory()->create(['status' => $status, 'source' => 'nutritionist']);

            $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", [
                'meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
            ], $auth)->assertUnprocessable()->assertJsonValidationErrors('meals.0.items.0.food_id');

            $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", [
                'meals' => [['name' => 'lunch', 'items' => [['food_id' => $approved->id, 'quantity_grams' => 100, 'alternatives' => [['food_id' => $food->id, 'quantity_grams' => 100]]]]]],
            ], $auth)->assertUnprocessable()->assertJsonValidationErrors('meals.0.items.0.alternatives.0.food_id');

            $this->postJson('/api/v1/me/meal-logs', ['food_id' => $food->id, 'quantity_grams' => 100], $clientAuth)
                ->assertUnprocessable()->assertJsonValidationErrors('food_id');
        }

        $this->assertDatabaseCount('meal_plans', 0);
        $this->assertDatabaseCount('meal_logs', 0);
    }

    // ---- helpers --------------------------------------------------------

    private function planUsing(Food $food, ?int $subscriberId = null, ?int $createdBy = null): int
    {
        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriberId,
            'created_by' => $createdBy ?? User::factory()->nutritionist()->create()->id,
            'status' => 'draft',
            'is_template' => $subscriberId === null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('meal_items')->insert(['meal_id' => $mealId, 'food_id' => $food->id, 'quantity_grams' => 100, 'created_at' => now(), 'updated_at' => now()]);

        return $planId;
    }

    private function logUsing(Food $food, ?Subscriber $subscriber = null): void
    {
        DB::table('meal_logs')->insert([
            'subscriber_id' => ($subscriber ?? Subscriber::factory()->active()->create())->id,
            'food_id' => $food->id, 'quantity_grams' => 100, 'logged_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One row of every kind of data a patient accumulates. */
    private function fillClientData(Subscriber $subscriber, Food $food): void
    {
        $id = $subscriber->id;
        $now = now();
        DB::table('health_profiles')->insert(['subscriber_id' => $id, 'weight_kg' => 80, 'height_cm' => 175, 'age' => 30, 'gender' => 'male', 'activity_level' => 'moderate', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('body_composition_readings')->insert(['subscriber_id' => $id, 'recorded_at' => $now->toDateString(), 'weight_kg' => 80, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('client_invites')->insert(['subscriber_id' => $id, 'token_hash' => hash('sha256', 'invite'.$id), 'expires_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('alerts')->insert(['subscriber_id' => $id, 'type' => 'no_log', 'message' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('ai_summaries')->insert(['subscriber_id' => $id, 'week_start' => $now->toDateString(), 'summary_text' => 'x', 'generated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('refresh_tokens')->insert(['user_id' => $subscriber->user_id, 'token_hash' => hash('sha256', 'refresh'.$id), 'expires_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
        $this->planUsing($food, $id, $subscriber->nutritionist_id);
        $this->logUsing($food, $subscriber);
    }

    /** @return array<string, int> */
    private function countsFor(Subscriber $subscriber): array
    {
        $counts = [];
        foreach (['health_profiles', 'body_composition_readings', 'client_invites', 'alerts', 'ai_summaries', 'meal_plans', 'meal_logs'] as $table) {
            $counts[$table] = DB::table($table)->where('subscriber_id', $subscriber->id)->count();
        }
        $plans = DB::table('meal_plans')->where('subscriber_id', $subscriber->id)->pluck('id');
        $counts['meals'] = DB::table('meals')->whereIn('meal_plan_id', $plans)->count();
        $counts['refresh_tokens'] = DB::table('refresh_tokens')->where('user_id', $subscriber->user_id)->count();

        return $counts;
    }
}
