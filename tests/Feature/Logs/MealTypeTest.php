<?php

namespace Tests\Feature\Logs;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-16: every meal log carries the meal of the day it belongs to. On-plan
 * logs get it from the plan meal, whatever the client sends; off-plan logs
 * take what the client sent, or have it inferred from the local time.
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

    /** A whole day in the past, so any time of it is a valid (not future) logged_at. */
    private function yesterday(): string
    {
        return CarbonImmutable::yesterday('UTC')->toDateString();
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

    /**
     * Every boundary of the default ranges, in the offset the app sent:
     * breakfast 05:00–10:59, lunch 11:00–16:59, dinner 17:00–22:59, else snack.
     */
    public function test_an_off_plan_log_without_meal_type_is_filed_by_its_local_time(): void
    {
        $day = $this->yesterday();

        $cases = [
            '00:00' => 'snack', '04:59' => 'snack', '05:00' => 'breakfast', '10:59' => 'breakfast',
            '11:00' => 'lunch', '16:59' => 'lunch', '17:00' => 'dinner', '22:59' => 'dinner', '23:00' => 'snack',
        ];

        foreach ($cases as $time => $expected) {
            // +03:00 — so 05:00 local is 02:00 UTC: only the local time counts.
            $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T{$time}:00+03:00"])
                ->assertCreated()
                ->assertJsonPath('meal_type', $expected, "{$time} local");
        }
    }

    public function test_without_an_offset_the_app_timezone_decides(): void
    {
        $day = $this->yesterday();
        $this->assertSame('UTC', config('app.timezone'));

        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T10:59:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'breakfast');
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T11:00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'lunch');

        // The same instant sent with +03:00 is 14:00 local: lunch, not breakfast.
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T14:00:00+03:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'lunch');
    }

    public function test_without_logged_at_the_current_time_in_the_app_timezone_decides(): void
    {
        // Token first: JWTs are checked against the real clock, not the travelled one.
        $token = $this->bearerFor($this->client);
        $this->travelTo(CarbonImmutable::today('UTC')->setTime(18, 30));

        $this->postJson('/api/v1/me/meal-logs', ['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80], $token)
            ->assertCreated()->assertJsonPath('meal_type', 'dinner');
    }

    public function test_the_ranges_come_from_config(): void
    {
        config(['patient_app.meal_times.breakfast' => '06:30-09:00']);
        $day = $this->yesterday();

        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T06:00:00+00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'snack');
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T06:30:00+00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'breakfast');
    }

    public function test_an_offset_time_is_stored_as_the_same_instant(): void
    {
        $day = $this->yesterday();

        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T08:00:00+03:00"])
            ->assertCreated()
            ->assertJsonPath('logged_at', "{$day}T05:00:00+00:00");
    }

    /** An explicit value always wins, and is read case-insensitively. */
    public function test_an_explicit_meal_type_is_kept_whatever_the_time(): void
    {
        $day = $this->yesterday();

        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => 'dinner', 'logged_at' => "{$day}T07:00:00+00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'dinner');
        $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => ' Lunch ', 'logged_at' => "{$day}T07:00:00+00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'lunch');
    }

    /** An unrecognisable value is not a reason to lose the entry: it is inferred instead. */
    public function test_an_unrecognised_meal_type_is_inferred_rather_than_refused(): void
    {
        $day = $this->yesterday();

        foreach (['brunch', '', 42] as $bad) {
            $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'meal_type' => $bad, 'logged_at' => "{$day}T12:00:00+00:00"])
                ->assertCreated()->assertJsonPath('meal_type', 'lunch');
        }
    }

    public function test_an_inferred_meal_type_can_be_changed_within_the_edit_window(): void
    {
        $day = $this->yesterday();
        $id = $this->logMeal(['food_id' => Food::factory()->create()->id, 'quantity_grams' => 80, 'logged_at' => "{$day}T12:30:00+00:00"])
            ->assertCreated()->assertJsonPath('meal_type', 'lunch')->json('id');

        $this->patchJson("/api/v1/me/meal-logs/{$id}", ['meal_type' => 'snack'], $this->bearerFor($this->client))
            ->assertOk()->assertJsonPath('meal_type', 'snack');
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
