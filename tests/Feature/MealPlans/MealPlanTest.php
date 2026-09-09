<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S3-01/S3-02/S3-04/S3-07: build, view, and activate a plan; BR-4 alternatives; FR-14 live macros. */
class MealPlanTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function payload(int $mainFoodId, int $altFoodId): array
    {
        return [
            'meals' => [
                [
                    'name' => 'breakfast',
                    'items' => [[
                        'food_id' => $mainFoodId,
                        'quantity_grams' => 200,
                        'alternatives' => [
                            ['food_id' => $altFoodId, 'quantity_grams' => 150],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function test_a_nutritionist_can_build_a_plan_with_an_alternative_and_see_live_macros(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $main = Food::factory()->create(['calories_per_100g' => 200, 'protein_g_per_100g' => 10, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 5]);
        $alt = Food::factory()->create();

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans",
            $this->payload($main->id, $alt->id),
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();
        $this->assertSame('draft', $response->json('status'));
        $this->assertCount(1, $response->json('meals.0.items'));
        $this->assertCount(1, $response->json('meals.0.items.0.alternatives'));
        // 200g of a 200 kcal/100g food = 400 kcal — planned item only, the
        // alternative never inflates the meal/day total (see
        // MealPlanCalculatorService's docblock). assertEquals, not
        // assertSame: json_encode drops the ".0" off a whole-number
        // float, so it round-trips through the response as int 400.
        $this->assertEquals(400, $response->json('meals.0.macros.calories'));
        $this->assertEquals(400, $response->json('summary_by_day.0.calories'));
    }

    public function test_activating_a_plan_archives_the_previously_active_one(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $food = Food::factory()->create();
        $auth = $this->bearerFor($nutritionist);

        $first = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $this->payload($food->id, $food->id), $auth);
        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$first->json('id')}/activate", [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $second = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", $this->payload($food->id, $food->id), $auth);
        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$second->json('id')}/activate", [], $auth)
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('meal_plans', ['id' => $first->json('id'), 'status' => 'archived']);
        $this->assertDatabaseHas('meal_plans', ['id' => $second->json('id'), 'status' => 'active']);
    }

    public function test_a_nutritionist_cannot_write_another_nutritionists_client_plan(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $othersClient = Subscriber::factory()->create();
        $food = Food::factory()->create();

        $this->postJson(
            "/api/v1/clients/{$othersClient->id}/meal-plans",
            $this->payload($food->id, $food->id),
            $this->bearerFor($nutritionistA)
        )->assertNotFound();
    }

    /**
     * The write-path test above doesn't prove the read path is isolated
     * too — `index`/`show` are separate code paths with their own
     * `abort_unless($subscriber->belongsToCaller(), 404)` checks, and a
     * bug in one wouldn't be caught by a test that only exercises the
     * other.
     */
    public function test_a_nutritionist_cannot_view_another_nutritionists_client_plans(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $nutritionistB = User::factory()->nutritionist()->create();
        $othersClient = Subscriber::factory()->create(['nutritionist_id' => $nutritionistB->id]);
        $food = Food::factory()->create();

        $plan = $this->postJson(
            "/api/v1/clients/{$othersClient->id}/meal-plans",
            $this->payload($food->id, $food->id),
            $this->bearerFor($nutritionistB)
        );

        $this->getJson("/api/v1/clients/{$othersClient->id}/meal-plans", $this->bearerFor($nutritionistA))
            ->assertNotFound();

        $this->getJson(
            "/api/v1/clients/{$othersClient->id}/meal-plans/{$plan->json('id')}",
            $this->bearerFor($nutritionistA)
        )->assertNotFound();
    }

    public function test_a_client_sees_only_their_own_active_plan_and_204_before_one_exists(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->forNutritionist($nutritionist)->create();
        $client = $subscriber->user;
        $food = Food::factory()->create();

        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertNoContent();

        $plan = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans",
            $this->payload($food->id, $food->id),
            $this->bearerFor($nutritionist)
        );
        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan->json('id')}/activate", [], $this->bearerFor($nutritionist));

        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))
            ->assertOk()
            ->assertJsonPath('id', $plan->json('id'));
    }

    public function test_saving_and_applying_a_template_clones_meals_into_a_new_draft(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriberA = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $subscriberB = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $food = Food::factory()->create();
        $auth = $this->bearerFor($nutritionist);

        $plan = $this->postJson("/api/v1/clients/{$subscriberA->id}/meal-plans", $this->payload($food->id, $food->id), $auth);

        $template = $this->postJson(
            "/api/v1/clients/{$subscriberA->id}/meal-plans/{$plan->json('id')}/save-as-template",
            [],
            $auth
        );
        $template->assertCreated();
        $this->assertTrue($template->json('is_template'));
        $this->assertNull($template->json('subscriber_id'));

        $applied = $this->postJson(
            "/api/v1/meal-plan-templates/{$template->json('id')}/apply/{$subscriberB->id}",
            [],
            $auth
        );
        $applied->assertCreated();
        $this->assertSame($subscriberB->id, $applied->json('subscriber_id'));
        $this->assertSame('draft', $applied->json('status'));
        $this->assertCount(1, $applied->json('meals.0.items'));
    }
}
