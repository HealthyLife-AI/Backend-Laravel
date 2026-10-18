<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Nutrition\CalorieTarget;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Item C: the nutritionist's plan decision — the calorie target she
 * approved and her written summary. Hers to set, hers alone to read.
 */
class PlanDecisionTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->food = Food::factory()->create(['status' => 'approved']);

        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => 80, 'height_cm' => 170, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary',
            'daily_calorie_needs' => 1800,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return $extra + [
            'meals' => [[
                'name' => 'breakfast',
                'items' => [['food_id' => $this->food->id, 'quantity_grams' => 200]],
            ]],
        ];
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    public function test_she_approves_a_target_and_writes_a_summary(): void
    {
        $response = $this->postJson(
            "/api/v1/clients/{$this->subscriber->id}/meal-plans",
            $this->payload(['approved_calories' => 1650, 'plan_summary' => 'خفض تدريجي مع بروتين أعلى.']),
            $this->token(),
        )->assertCreated();

        $response->assertJsonPath('approved_calories', 1650)->assertJsonPath('plan_summary', 'خفض تدريجي مع بروتين أعلى.');
        $this->assertNotNull($response->json('summary_updated_at'));
    }

    /** A typo guard only — 800..6000. */
    public function test_an_out_of_range_target_is_rejected(): void
    {
        foreach ([799, 6001] as $calories) {
            $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(['approved_calories' => $calories]), $this->token())
                ->assertStatus(422)->assertJsonValidationErrors('approved_calories');
        }

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(['plan_summary' => str_repeat('ا', 1001)]), $this->token())
            ->assertStatus(422)->assertJsonValidationErrors('plan_summary');
    }

    public function test_the_effective_target_is_hers_when_set_and_the_formula_otherwise(): void
    {
        $this->assertSame(1800, CalorieTarget::effective($this->subscriber));

        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(['approved_calories' => 1650]), $this->token())->json();
        // A draft is not in force yet.
        $this->assertSame(1800, CalorieTarget::effective($this->subscriber->fresh()));

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();
        $this->assertSame(1650, CalorieTarget::effective($this->subscriber->fresh()));
    }

    /** Activation freezes what the formula said, so a later profile edit can't rewrite the history. */
    public function test_activation_snapshots_the_system_suggestion(): void
    {
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(['approved_calories' => 1650]), $this->token())->json();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())
            ->assertOk()->assertJsonPath('suggested_calories', 1800);

        $this->subscriber->healthProfile->update(['daily_calorie_needs' => 2100]);
        $this->assertSame(1800, (int) MealPlan::find($plan['id'])->suggested_calories);
    }

    /**
     * She may revise the decision on the ACTIVE plan without
     * re-activating: neither field reaches the patient, so BR-6 is not
     * in play.
     */
    public function test_the_decision_is_editable_on_an_active_plan_without_reactivating(): void
    {
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(['approved_calories' => 1650]), $this->token())->json();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $before = MealPlan::find($plan['id']);
        $planCount = MealPlan::where('subscriber_id', $this->subscriber->id)->count();

        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/decision", [
            'approved_calories' => 1500, 'plan_summary' => 'خفضناها بعد مراجعة التحاليل.',
        ], $this->token())->assertOk()->assertJsonPath('approved_calories', 1500)->assertJsonPath('status', 'active');

        $after = MealPlan::find($plan['id']);
        $this->assertSame('active', $after->status);
        $this->assertEquals($before->activated_at, $after->activated_at);
        $this->assertSame($planCount, MealPlan::where('subscriber_id', $this->subscriber->id)->count());
        // In force immediately.
        $this->assertSame(1500, CalorieTarget::effective($this->subscriber->fresh()));

        // Clearing it falls back to the formula.
        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/decision", ['approved_calories' => null], $this->token())->assertOk();
        $this->assertSame(1800, CalorieTarget::effective($this->subscriber->fresh()));
    }

    public function test_the_decision_endpoint_is_scoped_to_the_caller(): void
    {
        $stranger = User::factory()->nutritionist()->create();
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload(), $this->token())->json();

        $this->patchJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/decision", ['approved_calories' => 1500], $this->bearerFor($stranger))
            ->assertNotFound();
    }

    /** C6: none of it reaches the patient. */
    public function test_the_patient_never_sees_the_decision(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);

        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload([
            'approved_calories' => 1650, 'plan_summary' => 'ملاحظات خاصة بالأخصائية.',
        ]), $this->token())->json();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $theirs = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertOk();

        foreach (['approved_calories', 'suggested_calories', 'plan_summary', 'summary_updated_at'] as $field) {
            $this->assertArrayNotHasKey($field, $theirs->json());
        }
        $this->assertStringNotContainsString('ملاحظات خاصة', $theirs->getContent());
    }

    /** C7: a template is the meals, not a decision about one patient. */
    public function test_templates_carry_no_decision(): void
    {
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->payload([
            'approved_calories' => 1650, 'plan_summary' => 'خاص بهذه الحالة.',
        ]), $this->token())->json();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->token())->assertOk();

        $template = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/save-as-template", ['name' => 'قالب'], $this->token())
            ->assertCreated()
            ->assertJsonPath('approved_calories', null)
            ->assertJsonPath('suggested_calories', null)
            ->assertJsonPath('plan_summary', null)
            ->json();

        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->postJson("/api/v1/meal-plan-templates/{$template['id']}/apply/{$other->id}", [], $this->token())
            ->assertCreated()
            ->assertJsonPath('approved_calories', null)
            ->assertJsonPath('plan_summary', null);
    }
}
