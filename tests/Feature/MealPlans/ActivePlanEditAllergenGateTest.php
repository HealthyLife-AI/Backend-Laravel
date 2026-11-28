<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\PatientAllergy;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Audit A-02: an edit to an ACTIVE plan reaches the patient immediately, so
 * it must not bring in a confirmed-allergy food without the same explicit
 * acknowledgement activation needs.
 */
class ActivePlanEditAllergenGateTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    private Food $lentils;

    private Food $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800,
        ]);
        $this->lentils = $this->food('Lentils, cooked', []);
        $this->milk = $this->food('Milk, whole', ['milk_lactose']);
    }

    /** @param  list<string>  $allergens */
    private function food(string $name, array $allergens): Food
    {
        $food = Food::factory()->create(['name_en' => $name, 'name_ar' => null]);
        $food->forceFill(['allergens' => $allergens])->saveQuietly();

        return $food;
    }

    private function milkAllergy(): void
    {
        PatientAllergy::create(['subscriber_id' => $this->subscriber->id, 'group' => 'milk_lactose', 'other_text' => null, 'class' => 'confirmed_allergy']);
    }

    /** @param  list<Food>  $foods */
    private function meals(array $foods): array
    {
        return [['name' => 'snack', 'items' => array_map(fn (Food $f) => ['food_id' => $f->id, 'quantity_grams' => 50], $foods)]];
    }

    private function plan(bool $activate): int
    {
        $id = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", ['meals' => $this->meals([$this->lentils])], $this->bearerFor($this->nutritionist))
            ->assertCreated()->json('id');

        if ($activate) {
            $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$id}/activate", [], $this->bearerFor($this->nutritionist))->assertOk();
        }

        return $id;
    }

    /** @param  list<Food>  $foods */
    private function edit(int $id, array $foods, array $extra = []): TestResponse
    {
        return $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$id}", ['meals' => $this->meals($foods)] + $extra, $this->bearerFor($this->nutritionist));
    }

    private function planHasFood(int $planId, Food $food): bool
    {
        return MealItem::query()->where('food_id', $food->id)->whereHas('meal', fn ($q) => $q->where('meal_plan_id', $planId))->exists();
    }

    public function test_an_edit_adding_a_confirmed_allergen_to_an_active_plan_is_refused_and_nothing_is_saved(): void
    {
        $this->milkAllergy();
        $id = $this->plan(activate: true);

        $this->edit($id, [$this->lentils, $this->milk])
            ->assertStatus(422)
            ->assertJsonPath('code', 'allergen_conflict_unacknowledged')
            ->assertJsonPath('allergen_conflicts.0.food_id', $this->milk->id);

        $this->assertFalse($this->planHasFood($id, $this->milk), 'the refused edit still reached the active plan');
    }

    public function test_the_same_edit_saves_with_the_acknowledgement_and_records_it(): void
    {
        $this->milkAllergy();
        $id = $this->plan(activate: true);

        $this->edit($id, [$this->lentils, $this->milk], ['acknowledge_allergen_conflicts' => true])
            ->assertOk()
            ->assertJsonPath('new_unacknowledged_conflicts', false);

        $this->assertTrue($this->planHasFood($id, $this->milk));
        $this->assertNotNull(MealPlan::find($id)->allergen_ack_at);
    }

    public function test_a_draft_edit_is_never_blocked(): void
    {
        $this->milkAllergy();
        $id = $this->plan(activate: false);

        $this->edit($id, [$this->lentils, $this->milk])->assertOk();
    }

    public function test_an_unrelated_edit_to_an_already_flagged_plan_is_not_blocked(): void
    {
        // The allergy is approved AFTER activation: the plan is flagged (red
        // warning), and only a change that brings in a new conflict blocks.
        $id = $this->plan(activate: true);
        $this->edit($id, [$this->lentils, $this->milk])->assertOk();
        $this->milkAllergy();

        $this->edit($id, [$this->milk, $this->lentils])->assertOk()->assertJsonPath('new_unacknowledged_conflicts', true);
    }

    public function test_adding_a_safe_food_to_an_active_plan_needs_nothing(): void
    {
        $this->milkAllergy();
        $id = $this->plan(activate: true);
        $bread = $this->food('Rice cakes', []);

        $this->edit($id, [$this->lentils, $bread])->assertOk();
    }
}
