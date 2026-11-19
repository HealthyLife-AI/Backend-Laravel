<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S3-06/S3-07 / F-5 (PRD), BR-6/BR-10: the AI draft is always editable and never auto-sent to the client. */
class AiDraftPlanTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_draft_targets_the_clients_calorie_needs_and_excludes_an_allergen(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'allergies' => ['peanut'],
            'daily_calorie_needs' => 2000,
        ]);
        $peanutDish = Food::factory()->create(['name_en' => 'Peanut Butter Toast', 'calories_per_100g' => 300]);
        $safeDish = Food::factory()->create(['name_en' => 'Grilled Chicken', 'calories_per_100g' => 200]);

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();
        $this->assertTrue($response->json('is_ai_draft'));
        // BR-6/BR-10: an AI draft is never active.
        $this->assertSame('draft', $response->json('status'));

        $usedFoodIds = collect($response->json('meals'))
            ->flatMap(fn ($meal) => $meal['items'])
            ->flatMap(fn ($item) => [$item['food']['id'], ...collect($item['alternatives'])->pluck('food.id')])
            ->unique();

        $this->assertNotContains($peanutDish->id, $usedFoodIds);
    }

    /**
     * S6 security hardening: this is a real, paid/quota-limited external
     * LLM call, not a DB read — throttled tighter than the general api
     * limit so a double-click loop or a script can't burn the shared Groq
     * quota the fallback depends on staying available (routes/api.php).
     */
    public function test_the_endpoint_is_rate_limited_to_5_per_minute(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'daily_calorie_needs' => 2000,
        ]);
        Food::factory()->create();
        $auth = $this->bearerFor($nutritionist);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", [], $auth)
                ->assertCreated();
        }

        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", [], $auth)
            ->assertStatus(429);
    }

    /**
     * Regression test: the quantity a food is scaled to (50g-500g) is
     * elastic enough that almost any food can be made to "fit" almost
     * any calorie target — ranking candidates by fit alone, with no
     * diversity rule, converged on the same one or two foods as the
     * PLANNED item for every meal in a real generated draft (caught by
     * actually reading one, not a unit test). Several same-shaped foods
     * here reproduce that degenerate case if the fix regresses.
     */
    public function test_a_draft_does_not_repeat_the_same_planned_food_across_meals(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'daily_calorie_needs' => 2000,
        ]);

        // All the same macro shape and calorie density as each other —
        // exactly the condition that made every meal's best "fit" match
        // identically before the diversity rule existed.
        for ($i = 0; $i < 6; $i++) {
            Food::factory()->create(['name_en' => "Rice variant {$i}", 'calories_per_100g' => 130]);
        }

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();

        $plannedFoodIds = collect($response->json('meals'))
            ->map(fn ($meal) => $meal['items'][0]['food']['id']);

        $this->assertSame($plannedFoodIds->unique()->count(), $plannedFoodIds->count(), 'every meal should plan a different headline food');
    }

    /**
     * fix/ai-draft-meal-slots: a food whose meal_slots excludes breakfast
     * must never be the rule-based generator's pick for breakfast, even
     * when it's the best calorie fit — the shape of the production bug
     * (شاورما دجاج offered for breakfast), reproduced here for the
     * fallback path specifically; AiDraftLlmTest covers the same rule
     * for an LLM response.
     */
    public function test_a_lunch_only_food_is_never_picked_for_breakfast_by_the_rule_based_generator(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'daily_calorie_needs' => 2000,
        ]);

        // Same calorie density as the safe alternative — without the
        // slot filter, ranking by fit alone can't tell them apart.
        $shawarma = Food::factory()->create(['name_en' => 'Chicken Shawarma', 'calories_per_100g' => 200, 'meal_slots' => ['lunch', 'dinner']]);
        $eggs = Food::factory()->create(['name_en' => 'Boiled Eggs', 'calories_per_100g' => 200, 'meal_slots' => null]);

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();

        $breakfast = collect($response->json('meals'))->firstWhere('name', 'breakfast');
        $this->assertNotNull($breakfast, 'the fallback still produced a breakfast meal');

        $breakfastFoodIds = collect($breakfast['items'])
            ->flatMap(fn ($item) => [$item['food']['id'], ...collect($item['alternatives'])->pluck('food.id')]);

        $this->assertNotContains($shawarma->id, $breakfastFoodIds);
        $this->assertContains($eggs->id, $breakfastFoodIds);

        // Shawarma legitimately appears somewhere it IS allowed — proof
        // the exclusion is scoped to breakfast, not a global ban.
        $allFoodIds = collect($response->json('meals'))
            ->flatMap(fn ($meal) => $meal['items'])
            ->flatMap(fn ($item) => [$item['food']['id'], ...collect($item['alternatives'])->pluck('food.id')]);

        $this->assertContains($shawarma->id, $allFoodIds);
    }

    public function test_a_draft_requires_a_health_profile_first(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        Food::factory()->create();

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft",
            [],
            $this->bearerFor($nutritionist)
        )->assertStatus(422);
    }

    public function test_a_draft_never_reaches_the_client_until_activated(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->forNutritionist($nutritionist)->create();
        HealthProfile::create([
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male',
            'activity_level' => 'sedentary',
            'daily_calorie_needs' => 2000,
        ]);
        Food::factory()->create();

        $draft = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($nutritionist));

        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($subscriber->user))->assertNoContent();

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/meal-plans/{$draft->json('id')}/activate",
            [],
            $this->bearerFor($nutritionist)
        );

        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($subscriber->user))
            ->assertOk()
            ->assertJsonPath('is_ai_draft', false); // activate() clears it — now just "the plan"
    }
}
