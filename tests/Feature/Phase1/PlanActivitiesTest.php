<?php

namespace Tests\Feature\Phase1;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\PatientNotification;
use App\Models\PlanActivity;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 1, A: physical activity is part of the plan. Written for the patient
 * (so it reaches the client role and the printout), edited with the plan or
 * on its own on an active plan, and copied wherever the meals are copied.
 */
class PlanActivitiesTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        $this->food = Food::factory()->create(['allergens' => []]);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female',
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function walk(array $extra = []): array
    {
        return $extra + ['title' => 'مشي خفيف', 'description' => 'بوتيرة مريحة', 'duration_minutes' => 30, 'times_per_day' => 1, 'days_per_week' => 5];
    }

    private function plan(array $extra = []): array
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $extra + [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 100]]]],
        ], $this->token())->assertCreated()->json();
    }

    private function url(int $planId, string $suffix = ''): string
    {
        return "/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}{$suffix}";
    }

    public function test_activities_are_stored_with_the_plan_in_order(): void
    {
        $plan = $this->plan(['activities' => [
            $this->walk(),
            ['title' => 'تمارين تمدد', 'duration_minutes' => 10, 'times_per_day' => 2],
        ]]);

        $this->assertCount(2, $plan['activities']);
        $this->assertSame('مشي خفيف', $plan['activities'][0]['title']);
        $this->assertEquals(['duration_minutes' => 10, 'times_per_day' => 2, 'days_per_week' => null, 'description' => null], collect($plan['activities'][1])->only(['duration_minutes', 'times_per_day', 'days_per_week', 'description'])->all(), 'times_per_day and days_per_week have sensible defaults');
        $this->assertSame([0, 1], array_column($plan['activities'], 'sort_order'));

        $this->getJson($this->url($plan['id']), $this->token())->assertOk()->assertJsonCount(2, 'activities');
    }

    public function test_a_plan_without_activities_returns_an_empty_list(): void
    {
        $this->assertSame([], $this->plan()['activities']);
    }

    public function test_the_numbers_are_validated(): void
    {
        $bad = [
            ['title' => '', 'duration_minutes' => 30],
            ['title' => str_repeat('ا', 121), 'duration_minutes' => 30],
            ['title' => 'x', 'duration_minutes' => 0],
            ['title' => 'x', 'duration_minutes' => 301],
            ['title' => 'x', 'duration_minutes' => 30, 'times_per_day' => 7],
            ['title' => 'x', 'duration_minutes' => 30, 'days_per_week' => 8],
            ['title' => 'x', 'duration_minutes' => 30, 'description' => str_repeat('ا', 1001)],
        ];

        foreach ($bad as $activity) {
            $response = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
                'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 100]]]],
                'activities' => [$activity],
            ], $this->token())->assertStatus(422);

            $this->assertNotEmpty(array_filter(array_keys($response->json('errors')), fn ($key) => str_starts_with($key, 'activities.0.')), json_encode($activity, JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame(0, PlanActivity::count());
    }

    public function test_the_edit_replaces_all_but_an_absent_key_leaves_them_and_an_empty_list_clears(): void
    {
        $plan = $this->plan(['activities' => [$this->walk(), $this->walk(['title' => 'ب'])]]);
        $meals = [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 120]]]];

        // Same plan saved again without the key: untouched.
        $this->putJson($this->url($plan['id']), ['meals' => $meals], $this->token())->assertOk()->assertJsonCount(2, 'activities');

        // Present: replaced as a whole.
        $this->putJson($this->url($plan['id']), ['meals' => $meals, 'activities' => [$this->walk(['title' => 'صعود الدرج', 'duration_minutes' => 10])]], $this->token())
            ->assertOk()->assertJsonCount(1, 'activities')->assertJsonPath('activities.0.title', 'صعود الدرج');

        // Empty list: cleared.
        $this->putJson($this->url($plan['id']), ['meals' => $meals, 'activities' => []], $this->token())->assertOk()->assertJsonCount(0, 'activities');
        $this->assertSame(0, PlanActivity::where('meal_plan_id', $plan['id'])->count());
    }

    public function test_they_are_editable_on_an_active_plan_without_reactivating_and_the_patient_is_told_generically(): void
    {
        $plan = $this->plan();
        $this->postJson($this->url($plan['id'], '/activate'), [], $this->token())->assertOk();
        $activatedAt = MealPlan::find($plan['id'])->activated_at;

        $this->patchJson($this->url($plan['id'], '/decision'), ['activities' => [$this->walk()]], $this->token())
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonCount(1, 'activities');

        $fresh = MealPlan::find($plan['id']);
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($activatedAt->equalTo($fresh->activated_at), 'no re-activation');
        $this->assertSame(1, MealPlan::where('subscriber_id', $this->subscriber->id)->count());

        // Same plan, same dedupe key as the activation: one notification, no activity text in it.
        $notes = PatientNotification::where('user_id', $this->client->id)->get();
        $this->assertCount(1, $notes);
        $this->assertStringNotContainsString('مشي', json_encode($notes->toArray(), JSON_UNESCAPED_UNICODE));

        // Sending the same activities again changes nothing and says nothing more.
        $this->patchJson($this->url($plan['id'], '/decision'), ['activities' => [$this->walk()]], $this->token())->assertOk();
        $this->assertCount(1, PatientNotification::where('user_id', $this->client->id)->get());
    }

    public function test_a_decision_edit_that_does_not_mention_activities_leaves_them(): void
    {
        $plan = $this->plan(['activities' => [$this->walk()]]);

        $this->patchJson($this->url($plan['id'], '/decision'), ['plan_summary' => 'ملاحظة'], $this->token())->assertOk()->assertJsonCount(1, 'activities');
    }

    public function test_a_duplicate_a_template_and_a_plan_from_a_template_carry_the_activities(): void
    {
        $plan = $this->plan(['activities' => [$this->walk(), $this->walk(['title' => 'ب', 'days_per_week' => null])]]);

        $copy = $this->postJson($this->url($plan['id'], '/duplicate'), [], $this->token())->assertCreated()->json();
        $this->assertSame(['مشي خفيف', 'ب'], array_column($copy['activities'], 'title'));
        $this->assertNotSame(array_column($plan['activities'], 'id'), array_column($copy['activities'], 'id'), 'copies, not shared rows');

        $template = $this->postJson($this->url($plan['id'], '/save-as-template'), ['name' => 'قالب'], $this->token())->assertSuccessful()->json();
        $this->assertSame(2, PlanActivity::where('meal_plan_id', $template['id'])->count());

        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $applied = $this->postJson("/api/v1/meal-plan-templates/{$template['id']}/apply/{$other->id}", [], $this->token())->assertSuccessful()->json();
        $this->assertSame(['مشي خفيف', 'ب'], PlanActivity::where('meal_plan_id', $applied['id'])->orderBy('sort_order')->pluck('title')->all());

        // Editing the copy never touches the original.
        $this->putJson($this->url($copy['id']), [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $this->food->id, 'quantity_grams' => 100]]]], 'activities' => [],
        ], $this->token())->assertOk();
        $this->assertSame(2, PlanActivity::where('meal_plan_id', $plan['id'])->count());
    }

    public function test_the_patient_sees_the_activities_on_the_plan_in_force_and_still_not_the_private_summary(): void
    {
        $plan = $this->plan(['activities' => [$this->walk()], 'plan_summary' => 'ملاحظة سريرية خاصة']);
        $this->postJson($this->url($plan['id'], '/activate'), [], $this->token())->assertOk();

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($this->client))->assertOk();

        $mine->assertJsonCount(1, 'activities')->assertJsonPath('activities.0.title', 'مشي خفيف')
            ->assertJsonPath('activities.0.duration_minutes', 30)->assertJsonPath('activities.0.times_per_day', 1)->assertJsonPath('activities.0.days_per_week', 5);
        $this->assertArrayNotHasKey('plan_summary', $mine->json());
        $this->assertStringNotContainsString('ملاحظة سريرية خاصة', $mine->getContent());
    }

    public function test_another_nutritionist_cannot_edit_the_activities(): void
    {
        $plan = $this->plan(['activities' => [$this->walk()]]);
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->patchJson($this->url($plan['id'], '/decision'), ['activities' => []], $stranger)->assertNotFound();
        $this->assertSame(1, PlanActivity::where('meal_plan_id', $plan['id'])->count());
    }
}
