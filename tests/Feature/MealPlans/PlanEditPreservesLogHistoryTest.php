<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Regression guard for a silent data bug: editing a plan used to delete
 * and rebuild every meal/item, and `meal_logs.meal_item_id` is
 * `nullOnDelete`, so every historical log attached to that plan lost its
 * reference on every edit. Adherence (S4-03) counts that column live over
 * any window, so a nutritionist adjusting one portion rewrote the
 * client's PAST adherence downward — and could flip them to `declining`
 * on the strength of their own edit.
 *
 * These tests assert the two halves of the rule: an item that survives
 * the edit keeps its id, and an item genuinely removed still releases
 * its logs (which is the behaviour the meal_logs migration intends).
 */
class PlanEditPreservesLogHistoryTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: Subscriber, 2: Food, 3: Food} */
    private function makeCase(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        return [$nutritionist, $subscriber, Food::factory()->create(), Food::factory()->create()];
    }

    private function payload(int $foodId, float $grams): array
    {
        return ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $foodId, 'quantity_grams' => $grams]]]]];
    }

    public function test_adjusting_a_portion_keeps_past_logs_attributed_to_the_plan(): void
    {
        [$nutritionist, $subscriber, $food] = $this->makeCase();
        $auth = $this->bearerFor($nutritionist);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $this->payload($food->id, 250), $auth)
            ->assertCreated()->json();
        $itemId = $plan['meals'][0]['items'][0]['id'];

        $log = MealLog::create([
            'subscriber_id' => $subscriber->id,
            'food_id' => $food->id,
            'meal_item_id' => $itemId,
            'quantity_grams' => 250,
            'logged_at' => CarbonImmutable::now()->subDay(),
        ]);

        // The nutritionist tunes the portion — same food, same meal.
        $this->putJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}",
            $this->payload($food->id, 300),
            $auth
        )->assertOk();

        $this->assertSame($itemId, $log->fresh()->meal_item_id, 'A surviving plan item must keep its id.');
        $this->assertDatabaseHas('meal_items', ['id' => $itemId, 'quantity_grams' => 300]);
    }

    /** The number on screen must not move because of an edit alone. */
    public function test_adherence_is_unchanged_by_editing_the_plan(): void
    {
        [$nutritionist, $subscriber, $food] = $this->makeCase();
        $auth = $this->bearerFor($nutritionist);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $this->payload($food->id, 250), $auth)
            ->assertCreated()->json();
        $itemId = $plan['meals'][0]['items'][0]['id'];

        MealLog::create([
            'subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'meal_item_id' => $itemId,
            'quantity_grams' => 250, 'logged_at' => CarbonImmutable::now()->subDay(),
        ]);

        $before = $this->getJson("/api/v1/clients/{$subscriber->id}/adherence", $auth)
            ->assertOk()->json('adherence_percent');

        $this->putJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}",
            $this->payload($food->id, 300),
            $auth
        )->assertOk();

        $after = $this->getJson("/api/v1/clients/{$subscriber->id}/adherence", $auth)
            ->assertOk()->json('adherence_percent');

        $this->assertSame(100.0, (float) $before);
        $this->assertSame((float) $before, (float) $after, 'Editing a plan must not move the client\'s past adherence.');
    }

    /**
     * The other half of the rule: swapping the food really is a different
     * item, so the old one goes and its logs become unattributed — the
     * behaviour `meal_logs`'s `nullOnDelete` was written for.
     */
    public function test_swapping_the_food_releases_the_old_items_logs(): void
    {
        [$nutritionist, $subscriber, $food, $otherFood] = $this->makeCase();
        $auth = $this->bearerFor($nutritionist);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $this->payload($food->id, 250), $auth)
            ->assertCreated()->json();
        $itemId = $plan['meals'][0]['items'][0]['id'];

        $log = MealLog::create([
            'subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'meal_item_id' => $itemId,
            'quantity_grams' => 250, 'logged_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->putJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}",
            $this->payload($otherFood->id, 250),
            $auth
        )->assertOk();

        $this->assertNull($log->fresh()->meal_item_id);
        $this->assertDatabaseMissing('meal_items', ['id' => $itemId]);
        // The log itself survives — the client did eat that meal (FR-17).
        $this->assertDatabaseHas('meal_logs', ['id' => $log->id]);
    }

    public function test_removing_a_meal_entirely_removes_its_items(): void
    {
        [$nutritionist, $subscriber, $food, $otherFood] = $this->makeCase();
        $auth = $this->bearerFor($nutritionist);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", [
            'meals' => [
                ['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 250]]],
                ['name' => 'dinner', 'items' => [['food_id' => $otherFood->id, 'quantity_grams' => 150]]],
            ],
        ], $auth)->assertCreated()->json();

        $this->putJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}",
            $this->payload($food->id, 250),
            $auth
        )->assertOk();

        $refreshed = $this->getJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}", $auth)
            ->assertOk()->json();

        $this->assertCount(1, $refreshed['meals']);
        $this->assertSame('lunch', $refreshed['meals'][0]['name']);
    }

    /** Alternatives reconcile by food too, inside their own parent item. */
    public function test_an_unchanged_alternative_keeps_its_id(): void
    {
        [$nutritionist, $subscriber, $food, $altFood] = $this->makeCase();
        $auth = $this->bearerFor($nutritionist);

        $withAlternative = [
            'meals' => [[
                'name' => 'lunch',
                'items' => [[
                    'food_id' => $food->id,
                    'quantity_grams' => 250,
                    'alternatives' => [['food_id' => $altFood->id, 'quantity_grams' => 200]],
                ]],
            ]],
        ];

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $withAlternative, $auth)
            ->assertCreated()->json();
        $altId = $plan['meals'][0]['items'][0]['alternatives'][0]['id'];

        $this->putJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}", $withAlternative, $auth)
            ->assertOk();

        $this->assertDatabaseHas('meal_items', ['id' => $altId, 'food_id' => $altFood->id]);
    }
}
