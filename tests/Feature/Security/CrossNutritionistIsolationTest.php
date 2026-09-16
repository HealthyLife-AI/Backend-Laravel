<?php

namespace Tests\Feature\Security;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AiSummaries\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S6-02 / NFR-12: a formal, exhaustive pass — not another spot-check —
 * confirming nutritionist B can never reach nutritionist A's client data
 * by guessing an id. `Subscriber`'s own `NutritionistScope` only protects
 * EXPLICIT queries (`Subscriber::query()`, a relation); it does NOT
 * protect implicit route-model-bound parameters, because Laravel resolves
 * those via `SubstituteBindings`, which runs before the app's `jwt`
 * middleware authenticates anyone — see `Subscriber`'s own class
 * docblock. Every controller that receives a route-bound `Subscriber` (or
 * `MealPlan`, which carries no scope of its own at all) is required to
 * re-check ownership manually with `belongsToCaller()`/`abort_unless`.
 * That manual check is exactly the kind of thing that's easy to forget on
 * a new endpoint and easy to silently break in a refactor — this file
 * exists so a regression there fails loudly, in one place, rather than
 * being rediscovered as a real leak in Sprint 6/pilot.
 *
 * One test method per endpoint (not one giant test) so a failure here
 * names the exact leaking endpoint.
 */
class CrossNutritionistIsolationTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionistA;

    private User $nutritionistB;

    private Subscriber $subscriberA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionistA = User::factory()->nutritionist()->create();
        $this->nutritionistB = User::factory()->nutritionist()->create();
        $this->subscriberA = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionistA->id]);
    }

    private function asB(): array
    {
        return $this->bearerFor($this->nutritionistB);
    }

    public function test_client_show(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}", $this->asB())->assertNotFound();
    }

    public function test_health_profile_show(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/health-profile", $this->asB())->assertNotFound();
    }

    public function test_health_profile_update(): void
    {
        $this->putJson("/api/v1/clients/{$this->subscriberA->id}/health-profile", [
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 25,
            'gender' => 'female', 'activity_level' => 'sedentary',
        ], $this->asB())->assertNotFound();
    }

    public function test_body_composition_index(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/body-composition-readings", $this->asB())->assertNotFound();
    }

    public function test_body_composition_store(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriberA->id}/body-composition-readings", [
            'recorded_at' => now()->toDateString(), 'weight_kg' => 70,
        ], $this->asB())->assertNotFound();
    }

    public function test_meal_plan_index(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans", $this->asB())->assertNotFound();
    }

    public function test_meal_plan_store(): void
    {
        $food = Food::factory()->create();
        $this->postJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->asB())->assertNotFound();
    }

    public function test_meal_plan_show_update_activate_ai_draft(): void
    {
        $food = Food::factory()->create();
        $plan = $this->postJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->bearerFor($this->nutritionistA))->json();

        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans/{$plan['id']}", $this->asB())->assertNotFound();

        $this->putJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans/{$plan['id']}", [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->asB())->assertNotFound();

        $this->postJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans/{$plan['id']}/activate", [], $this->asB())->assertNotFound();

        $this->postJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans/ai-draft", [], $this->asB())->assertNotFound();
    }

    /**
     * Two-sided: B applying THEIR OWN template onto A's subscriber, and B
     * applying A's template onto anyone — both must 404, and they fail
     * for different reasons (`$subscriber->belongsToCaller()` vs
     * `$mealPlan->belongsToCaller()`), so both are worth proving.
     */
    public function test_meal_plan_template_apply_onto_another_nutritionists_client(): void
    {
        $food = Food::factory()->create();
        $subscriberB = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionistB->id]);
        $planB = $this->postJson("/api/v1/clients/{$subscriberB->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->asB())->json();
        $templateB = $this->postJson(
            "/api/v1/clients/{$subscriberB->id}/meal-plans/{$planB['id']}/save-as-template",
            [],
            $this->asB()
        )->json();

        // B's own template, but A's client -> 404 via subscriber ownership.
        $this->postJson(
            "/api/v1/meal-plan-templates/{$templateB['id']}/apply/{$this->subscriberA->id}",
            [],
            $this->asB()
        )->assertNotFound();
    }

    public function test_meal_plan_template_apply_of_another_nutritionists_template(): void
    {
        $food = Food::factory()->create();
        $planA = $this->postJson("/api/v1/clients/{$this->subscriberA->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->bearerFor($this->nutritionistA))->json();
        $templateA = $this->postJson(
            "/api/v1/clients/{$this->subscriberA->id}/meal-plans/{$planA['id']}/save-as-template",
            [],
            $this->bearerFor($this->nutritionistA)
        )->json();
        $subscriberB = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionistB->id]);

        // A's template applied by B onto B's own client -> 404 via template ownership.
        $this->postJson(
            "/api/v1/meal-plan-templates/{$templateA['id']}/apply/{$subscriberB->id}",
            [],
            $this->asB()
        )->assertNotFound();
    }

    public function test_adherence_show(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/adherence", $this->asB())->assertNotFound();
    }

    public function test_progress_show(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/progress", $this->asB())->assertNotFound();
    }

    public function test_ai_summaries_index(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriberA->id}/ai-summaries", $this->asB())->assertNotFound();
    }

    /** Confirms a real generated summary row is unreachable too, not just an empty list. */
    public function test_ai_summaries_index_does_not_leak_an_existing_summary(): void
    {
        app(WeeklySummaryService::class)->generateForWeek($this->subscriberA, CarbonImmutable::now()->subWeek()->startOfWeek());

        $response = $this->getJson("/api/v1/clients/{$this->subscriberA->id}/ai-summaries", $this->asB());

        $response->assertNotFound();
    }

    public function test_alerts_index_is_scoped_and_filterable_by_a_foreign_subscriber_id_without_leaking(): void
    {
        // subscriber_id is a plain filter param on /alerts, not a route
        // binding — confirms passing A's id doesn't smuggle A's alerts
        // into B's own (whereHas('subscriber')-scoped) result set.
        $response = $this->getJson("/api/v1/alerts?subscriber_id={$this->subscriberA->id}", $this->asB());

        $response->assertOk()->assertJsonCount(0, 'data');
    }
}
