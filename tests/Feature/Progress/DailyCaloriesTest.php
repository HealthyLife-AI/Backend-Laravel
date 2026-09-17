<?php

namespace Tests\Feature\Progress;

use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S4-07: the `daily_calories` series on GET /clients/{id}/progress —
 * planned versus actually-logged calories, one row per day.
 *
 * Its whole reason to exist is the dashboard's plan-vs-actual chart, and
 * the distinction that chart depends on is `planned_calories: null`
 * (nothing to compare against) versus a real number — never 0, which
 * would claim the plan prescribed no food.
 */
class DailyCaloriesTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: Subscriber} */
    private function makeClient(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        return [$nutritionist, $subscriber];
    }

    /**
     * A plan whose meals carry no `day_index` repeats every day (the
     * `meals` migration's "daily plan" shape).
     */
    private function activeDailyPlan(
        Subscriber $subscriber,
        User $nutritionist,
        Food $food,
        float $grams,
        ?string $activatedAt = null,
    ): void {
        // Defaults to "in force for a month" so a test that isn't about
        // the effective-start bound isn't silently truncated by it.
        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id,
            'status' => 'active',
            'activated_at' => $activatedAt ?? CarbonImmutable::now()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId, 'name' => 'lunch', 'day_index' => null,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('meal_items')->insert([
            'meal_id' => $mealId, 'food_id' => $food->id, 'quantity_grams' => $grams,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function progress(User $nutritionist, Subscriber $subscriber, string $from, string $to): array
    {
        return $this->getJson(
            "/api/v1/clients/{$subscriber->id}/progress?from={$from}&to={$to}",
            $this->bearerFor($nutritionist)
        )->assertOk()->json('daily_calories');
    }

    /**
     * JSON has one number type: `500.0` encodes as `500` and decodes as an
     * int, so a raw assertSame against a float fails on a value that is
     * perfectly correct. Casting keeps the assertion strict about the
     * VALUE without asserting a PHP type the wire format can't carry —
     * and the consumer is JavaScript, which draws no distinction either.
     */
    private function floats(array $days, string $key): array
    {
        return array_map(
            fn ($value) => $value === null ? null : (float) $value,
            array_column($days, $key),
        );
    }

    public function test_it_returns_one_row_per_day_in_the_window(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();
        $from = CarbonImmutable::now()->subDays(6)->toDateString();
        $to = CarbonImmutable::now()->toDateString();

        $days = $this->progress($nutritionist, $subscriber, $from, $to);

        $this->assertCount(7, $days);
        $this->assertSame($from, $days[0]['date']);
        $this->assertSame($to, $days[6]['date']);
    }

    /**
     * The distinction the chart is built on: no active plan means there
     * is nothing to compare against, which is not the same statement as
     * "the plan prescribed 0 calories".
     */
    public function test_planned_is_null_not_zero_when_the_client_has_no_active_plan(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();

        $days = $this->progress(
            $nutritionist,
            $subscriber,
            CarbonImmutable::now()->toDateString(),
            CarbonImmutable::now()->toDateString()
        );

        $this->assertNull($days[0]['planned_calories']);
        $this->assertSame([0.0], $this->floats($days, 'logged_calories'));
    }

    public function test_a_daily_plan_reports_the_same_planned_total_every_day(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();
        // 200 kcal/100g at 250g = 500 kcal, every day.
        $food = Food::factory()->create(['calories_per_100g' => 200]);
        $this->activeDailyPlan($subscriber, $nutritionist, $food, 250);

        $days = $this->progress(
            $nutritionist,
            $subscriber,
            CarbonImmutable::now()->subDays(2)->toDateString(),
            CarbonImmutable::now()->toDateString()
        );

        $this->assertSame([500.0, 500.0, 500.0], $this->floats($days, 'planned_calories'));
    }

    /**
     * The plan only applies from the day it took effect. Before this
     * bound, a plan activated today claimed the client had been
     * prescribed those calories all week and graded them against a plan
     * that did not exist yet.
     */
    public function test_days_before_the_plan_took_effect_have_no_planned_target(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();
        $food = Food::factory()->create(['calories_per_100g' => 200]);
        $this->activeDailyPlan(
            $subscriber,
            $nutritionist,
            $food,
            250,
            CarbonImmutable::now()->toDateString(), // activated today
        );

        $days = $this->progress(
            $nutritionist,
            $subscriber,
            CarbonImmutable::now()->subDays(2)->toDateString(),
            CarbonImmutable::now()->toDateString()
        );

        $this->assertSame([null, null, 500.0], $this->floats($days, 'planned_calories'));
    }

    public function test_logged_calories_are_summed_per_day_from_what_was_actually_eaten(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();
        $food = Food::factory()->create(['calories_per_100g' => 100]);
        $today = CarbonImmutable::now();

        // 150g + 50g on the same day = 150 + 50 = 200 kcal.
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 150, 'logged_at' => $today->setTime(8, 0)]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 50, 'logged_at' => $today->setTime(19, 0)]);
        // A different day must not be folded into it.
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 300, 'logged_at' => $today->subDay()->setTime(12, 0)]);

        $days = $this->progress(
            $nutritionist,
            $subscriber,
            $today->subDay()->toDateString(),
            $today->toDateString()
        );

        $this->assertSame([300.0, 200.0], $this->floats($days, 'logged_calories'));
    }

    /** Off-plan logs still count as eaten — this series is calories, not adherence. */
    public function test_off_plan_logs_count_toward_logged_calories(): void
    {
        [$nutritionist, $subscriber] = $this->makeClient();
        $planned = Food::factory()->create(['calories_per_100g' => 200]);
        $other = Food::factory()->create(['calories_per_100g' => 400]);
        $this->activeDailyPlan($subscriber, $nutritionist, $planned, 100);

        // meal_item_id null = eaten outside the plan (BR-9).
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $other->id, 'quantity_grams' => 100, 'logged_at' => CarbonImmutable::now()]);

        $days = $this->progress(
            $nutritionist,
            $subscriber,
            CarbonImmutable::now()->toDateString(),
            CarbonImmutable::now()->toDateString()
        );

        $this->assertSame([200.0], $this->floats($days, 'planned_calories'));
        $this->assertSame([400.0], $this->floats($days, 'logged_calories'));
    }

    /** Same isolation rule as the rest of the endpoint (NFR-12). */
    public function test_another_nutritionist_cannot_read_the_series(): void
    {
        [, $subscriber] = $this->makeClient();
        $other = User::factory()->nutritionist()->create();

        $this->getJson("/api/v1/clients/{$subscriber->id}/progress", $this->bearerFor($other))
            ->assertNotFound();
    }
}
