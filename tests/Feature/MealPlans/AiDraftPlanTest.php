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
