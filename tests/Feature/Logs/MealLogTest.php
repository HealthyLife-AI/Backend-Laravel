<?php

namespace Tests\Feature\Logs;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S4-01 / FR-17, BR-9: the client logs what they actually ate, and each
 * log records whether it corresponds to a planned item, one of its
 * permitted alternatives, or nothing in the plan at all.
 */
class MealLogTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * A client user with an active subscriber row, plus a one-meal plan
     * assigned to them holding one planned item and one alternative.
     *
     * @return array{0: User, 1: Subscriber, 2: MealItem, 3: MealItem}
     */
    private function makeClientWithPlan(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');

        $subscriber = Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id,
            'created_by' => $nutritionist->id,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId,
            'name' => 'lunch', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $planned = MealItem::create([
            'meal_id' => $mealId,
            'food_id' => Food::factory()->create(['name_en' => 'Chicken Kabsa'])->id,
            'quantity_grams' => 300,
            'sort_order' => 0,
        ]);
        $alternative = MealItem::create([
            'meal_id' => $mealId,
            'food_id' => Food::factory()->create(['name_en' => 'Mujaddara'])->id,
            'parent_item_id' => $planned->id,
            'quantity_grams' => 250,
            'sort_order' => 1,
        ]);

        return [$client, $subscriber, $planned, $alternative];
    }

    public function test_a_client_logs_a_planned_item_and_it_counts_as_on_plan(): void
    {
        [$client, $subscriber, $planned] = $this->makeClientWithPlan();

        $response = $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $planned->food_id,
            'meal_item_id' => $planned->id,
            'quantity_grams' => 300,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertTrue($response->json('is_on_plan'));
        $this->assertDatabaseHas('meal_logs', [
            'subscriber_id' => $subscriber->id,
            'meal_item_id' => $planned->id,
        ]);
    }

    /** BR-9 treats an alternative as on-plan too — it's a permitted substitute, not a deviation. */
    public function test_logging_a_permitted_alternative_also_counts_as_on_plan(): void
    {
        [$client, , , $alternative] = $this->makeClientWithPlan();

        $response = $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $alternative->food_id,
            'meal_item_id' => $alternative->id,
            'quantity_grams' => 250,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertTrue($response->json('is_on_plan'));
    }

    public function test_a_food_outside_the_plan_is_logged_as_off_plan(): void
    {
        [$client] = $this->makeClientWithPlan();
        $cake = Food::factory()->create(['name_en' => 'Chocolate Cake']);

        $response = $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $cake->id,
            'quantity_grams' => 120,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertFalse($response->json('is_on_plan'));
        $this->assertNull($response->json('meal_item_id'));
    }

    /**
     * The isolation case. `meal_item_id` is validated by ownership, not
     * by a plain `exists` rule — an `exists` rule would confirm the row
     * exists somewhere and happily let one client mark another client's
     * plan item as eaten, corrupting that client's adherence (S4-03).
     */
    public function test_a_client_cannot_log_against_another_clients_meal_item(): void
    {
        [$client] = $this->makeClientWithPlan();
        [, , $otherPlanned] = $this->makeClientWithPlan();

        $response = $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $otherPlanned->food_id,
            'meal_item_id' => $otherPlanned->id,
            'quantity_grams' => 300,
        ], $this->bearerFor($client));

        $response->assertStatus(422)->assertJsonValidationErrors('meal_item_id');
        $this->assertDatabaseMissing('meal_logs', ['meal_item_id' => $otherPlanned->id]);
    }

    /** "I ate the planned item, but the food was something else" is not a coherent claim. */
    public function test_the_food_must_match_the_plan_item_it_is_logged_against(): void
    {
        [$client, , $planned] = $this->makeClientWithPlan();
        $somethingElse = Food::factory()->create(['name_en' => 'Chocolate Cake']);

        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $somethingElse->id,
            'meal_item_id' => $planned->id,
            'quantity_grams' => 300,
        ], $this->bearerFor($client))
            ->assertStatus(422)
            ->assertJsonValidationErrors('food_id');
    }

    public function test_logging_stamps_last_logged_at_on_the_subscriber(): void
    {
        [$client, $subscriber] = $this->makeClientWithPlan();
        $this->assertNull($subscriber->last_logged_at);

        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
        ], $this->bearerFor($client))->assertCreated();

        $this->assertNotNull($subscriber->fresh()->last_logged_at);
    }

    public function test_a_client_only_sees_their_own_logs(): void
    {
        [$client] = $this->makeClientWithPlan();
        [$otherClient] = $this->makeClientWithPlan();

        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
        ], $this->bearerFor($otherClient))->assertCreated();

        $response = $this->getJson('/api/v1/me/meal-logs', $this->bearerFor($client));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_the_history_can_be_narrowed_to_a_date_window(): void
    {
        [$client] = $this->makeClientWithPlan();
        $food = Food::factory()->create();
        $header = $this->bearerFor($client);

        foreach ([now()->subDays(10), now()->subDay()] as $when) {
            $this->postJson('/api/v1/me/meal-logs', [
                'food_id' => $food->id,
                'quantity_grams' => 100,
                'logged_at' => $when->toIso8601String(),
            ], $header)->assertCreated();
        }

        $all = $this->getJson('/api/v1/me/meal-logs', $header);
        $this->assertCount(2, $all->json('data'));

        $window = $this->getJson(
            '/api/v1/me/meal-logs?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString(),
            $header
        );

        $window->assertOk();
        $this->assertCount(1, $window->json('data'));
    }

    /** A half-open range would silently return everything — the opposite of what the caller asked for. */
    public function test_a_half_open_date_window_is_rejected(): void
    {
        [$client] = $this->makeClientWithPlan();

        $this->getJson('/api/v1/me/meal-logs?from='.now()->toDateString(), $this->bearerFor($client))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_a_nutritionist_cannot_use_the_client_logging_endpoint(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
        ], $this->bearerFor($nutritionist))->assertForbidden();
    }

    public function test_a_future_timestamp_is_rejected(): void
    {
        [$client] = $this->makeClientWithPlan();

        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
            'logged_at' => now()->addDay()->toIso8601String(),
        ], $this->bearerFor($client))
            ->assertStatus(422)
            ->assertJsonValidationErrors('logged_at');
    }

    /** The mobile app (S4-12) syncs offline entries with their real time, not the sync time. */
    public function test_a_backdated_timestamp_from_an_offline_entry_is_kept(): void
    {
        [$client] = $this->makeClientWithPlan();
        $eatenAt = now()->subHours(5)->startOfSecond();

        $response = $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100,
            'logged_at' => $eatenAt->toIso8601String(),
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertSame($eatenAt->toIso8601String(), $response->json('logged_at'));
    }
}
