<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Item E: "ابدأ من هذه الخطة" — a new draft from an existing plan. */
class DuplicatePlanTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    private Food $food;

    private Food $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->food = Food::factory()->create(['status' => 'approved']);
        $this->other = Food::factory()->create(['status' => 'approved']);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 80, 'height_cm' => 170, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    /** @return array<string, mixed> */
    private function makePlan(): array
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'approved_calories' => 1650,
            'plan_summary' => 'الدورة الأولى.',
            'meals' => [[
                'name' => 'breakfast',
                'items' => [[
                    'food_id' => $this->food->id,
                    'quantity_grams' => 200,
                    'alternatives' => [['food_id' => $this->other->id, 'quantity_grams' => 150]],
                ]],
            ]],
        ], $this->token())->assertCreated()->json();
    }

    private function duplicate(int|string $planId): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}/duplicate", [], $this->token());
    }

    public function test_the_copy_carries_the_meals_and_the_decision_and_is_a_draft(): void
    {
        $plan = $this->makePlan();

        $copy = $this->duplicate($plan['id'])->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('is_ai_draft', false)
            ->assertJsonPath('approved_calories', 1650)
            ->assertJsonPath('plan_summary', 'الدورة الأولى.')
            ->json();

        $this->assertNotSame($plan['id'], $copy['id']);
        $this->assertCount(1, $copy['meals']);
        $this->assertSame($this->food->id, $copy['meals'][0]['items'][0]['food']['id']);
        $this->assertCount(1, $copy['meals'][0]['items'][0]['alternatives']);
        $this->assertSame($this->other->id, $copy['meals'][0]['items'][0]['alternatives'][0]['food']['id']);
    }

    public function test_editing_the_copy_leaves_the_original_alone(): void
    {
        $plan = $this->makePlan();
        $copy = $this->duplicate($plan['id'])->assertCreated()->json();

        $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$copy['id']}", [
            'approved_calories' => 1400,
            'plan_summary' => 'الدورة الثانية.',
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->other->id, 'quantity_grams' => 300]]]],
        ], $this->token())->assertOk();

        $original = $this->getJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}", $this->token())->assertOk();
        $original->assertJsonPath('approved_calories', 1650)->assertJsonPath('plan_summary', 'الدورة الأولى.');
        $this->assertSame('breakfast', $original->json('meals.0.name'));
        $this->assertSame($this->food->id, $original->json('meals.0.items.0.food.id'));
    }

    /** The source keeps running until the copy is activated in its own right. */
    public function test_duplicating_an_active_plan_leaves_it_active_and_archives_it_only_on_activation(): void
    {
        $plan = $this->makePlan();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $copy = $this->duplicate($plan['id'])->assertCreated()->json();
        $this->assertSame('active', MealPlan::find($plan['id'])->status);
        $this->assertSame('draft', MealPlan::find($copy['id'])->status);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$copy['id']}/activate", [], $this->token())->assertOk();
        $this->assertSame('archived', MealPlan::find($plan['id'])->status);
        $this->assertSame('active', MealPlan::find($copy['id'])->status);
    }

    /** BR-6: a copy reaches the patient only once she activates it. */
    public function test_the_patient_still_sees_the_old_plan_until_the_copy_is_activated(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);

        $plan = $this->makePlan();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $copy = $this->duplicate($plan['id'])->assertCreated()->json();
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$copy['id']}", [
            'meals' => [['name' => 'dinner', 'items' => [['food_id' => $this->other->id, 'quantity_grams' => 400]]]],
        ], $this->token())->assertOk();

        $theirs = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertOk();
        $this->assertSame($plan['id'], $theirs->json('id'));
        $this->assertSame('breakfast', $theirs->json('meals.0.name'));
    }

    public function test_another_nutritionist_cannot_duplicate_the_plan(): void
    {
        $plan = $this->makePlan();
        $stranger = User::factory()->nutritionist()->create();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/duplicate", [], $this->bearerFor($stranger))
            ->assertNotFound();
    }

    /** Same follow-up gate as creating one. */
    public function test_duplicating_is_blocked_once_follow_up_has_ended(): void
    {
        $plan = $this->makePlan();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/archive", [], $this->token())->assertOk();

        $this->duplicate($plan['id'])->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
    }
}
