<?php

namespace Tests\Feature\Logs;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-16: every meal log carries the meal of the day it belongs to. Off-plan
 * logs must say which; on-plan logs get it from the plan meal, whatever the
 * client sends.
 */
class MealTypeTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    private User $client;

    private Subscriber $subscriber;

    /** @var array<string, MealItem> plan item per meal name */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $this->client->assignRole('client');
        $this->subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $this->client->id]);

        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $this->subscriber->id, 'created_by' => $nutritionist->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['breakfast', 'dinner'] as $order => $name) {
            $mealId = DB::table('meals')->insertGetId([
                'meal_plan_id' => $planId, 'name' => $name, 'sort_order' => $order,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->items[$name] = MealItem::create([
                'meal_id' => $mealId, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'sort_order' => 0,
            ]);
        }
    }

    private function logMeal(array $body, ?User $as = null)
    {
        return $this->postJson('/api/v1/me/meal-logs', $body, $this->bearerFor($as ?? $this->client));
    }

    public function test_an_off_plan_log_stores_and_returns_its_meal_type(): void
    {
        $response = $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'snack']);

        $response->assertCreated()->assertJsonPath('meal_type', 'snack')->assertJsonPath('is_on_plan', false);
        $this->assertDatabaseHas('meal_logs', ['subscriber_id' => $this->subscriber->id, 'meal_type' => 'snack']);
    }

    public function test_meal_type_is_required_for_an_off_plan_log(): void
    {
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80])
            ->assertUnprocessable()->assertJsonValidationErrors('meal_type');

        $this->assertDatabaseCount('meal_logs', 0);
    }

    public function test_meal_type_must_be_one_of_the_four_meals(): void
    {
        foreach (['brunch', 'Lunch', ''] as $bad) {
            $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors('meal_type');
        }
    }

    public function test_an_on_plan_log_takes_its_meal_type_from_the_plan_meal(): void
    {
        $item = $this->items['dinner'];

        $this->logMeal(['food_id' => $item->food_id, 'meal_item_id' => $item->id, 'quantity_grams' => 100])
            ->assertCreated()->assertJsonPath('meal_type', 'dinner')->assertJsonPath('is_on_plan', true);
    }

    public function test_a_meal_type_sent_with_an_on_plan_log_is_ignored(): void
    {
        $item = $this->items['breakfast'];

        // Neither a conflicting value nor an invalid one is an error: the
        // server sets it from the plan and discards what was sent.
        foreach (['dinner', 'brunch'] as $sent) {
            $this->logMeal(['food_id' => $item->food_id, 'meal_item_id' => $item->id, 'quantity_grams' => 100, 'meal_type' => $sent])
                ->assertCreated()->assertJsonPath('meal_type', 'breakfast');
        }
    }

    public function test_the_listing_returns_meal_type_on_every_log(): void
    {
        $item = $this->items['dinner'];
        $this->logMeal(['food_id' => $item->food_id, 'meal_item_id' => $item->id, 'quantity_grams' => 100])->assertCreated();
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'snack'])->assertCreated();

        $types = collect($this->getJson('/api/v1/me/meal-logs', $this->bearerFor($this->client))->assertOk()->json('data'))->pluck('meal_type')->sort()->values()->all();

        $this->assertSame(['dinner', 'snack'], $types);
    }

    public function test_a_replayed_entry_returns_the_original_meal_type(): void
    {
        $key = (string) Str::uuid();
        $body = ['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'lunch', 'idempotency_key' => $key];

        $this->logMeal($body)->assertCreated();
        $this->logMeal($body)->assertOk()->assertJsonPath('meal_type', 'lunch');
        $this->assertSame(1, $this->subscriber->mealLogs()->count());
    }

    public function test_a_nutritionist_cannot_use_the_logging_endpoint(): void
    {
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'lunch'], User::factory()->nutritionist()->create())
            ->assertForbidden();
    }

    public function test_an_archived_patient_gets_follow_up_ended(): void
    {
        $token = $this->bearerFor($this->client);
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->postJson('/api/v1/me/meal-logs', ['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'lunch'], $token)
            ->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->getJson('/api/v1/me/meal-logs', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
    }

    public function test_the_migration_backfills_on_plan_rows_leaves_off_plan_null_and_rolls_back(): void
    {
        $migration = $this->migrationFile('2026_09_29_110000_add_meal_type_to_meal_logs_table');
        $onPlan = $this->items['dinner'];
        $offPlanFood = Food::factory()->create();

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('meal_logs', 'meal_type'), 'rollback drops the column');

            $row = fn (array $extra) => $extra + ['subscriber_id' => $this->subscriber->id, 'quantity_grams' => 100, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            $onPlanId = DB::table('meal_logs')->insertGetId($row(['food_id' => $onPlan->food_id, 'meal_item_id' => $onPlan->id]));
            $offPlanId = DB::table('meal_logs')->insertGetId($row(['food_id' => $offPlanFood->id]));

            $migration->up();

            $this->assertSame('dinner', MealLog::find($onPlanId)->meal_type);
            $this->assertNull(MealLog::find($offPlanId)->meal_type);
        } finally {
            if (! Schema::hasColumn('meal_logs', 'meal_type')) {
                $migration->up();
            }
        }
    }
}
