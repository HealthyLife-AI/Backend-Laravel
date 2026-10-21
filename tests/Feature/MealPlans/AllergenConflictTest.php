<?php

namespace Tests\Feature\MealPlans;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\PatientAllergy;
use App\Models\PatientNotification;
use App\Models\ProfileProposal;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Pilot feedback 2, A: a food that conflicts with what the patient is
 * APPROVED to be allergic to is reported on the plan, blocks activation
 * (confirmed allergies only) until acknowledged, and is never visible to
 * the patient.
 */
class AllergenConflictTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

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

    private function food(array $attrs = []): Food
    {
        return Food::factory()->create($attrs + ['allergens' => []]);
    }

    private function allergy(string $group, string $class = 'confirmed_allergy', ?string $text = null): PatientAllergy
    {
        return PatientAllergy::create(['subscriber_id' => $this->subscriber->id, 'group' => $group, 'other_text' => $text, 'class' => $class]);
    }

    /** @param  list<array{food: Food, alternatives?: list<Food>}>  $items */
    private function plan(array $items, array $extra = []): array
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $extra + [
            'meals' => [[
                'name' => 'breakfast',
                'items' => array_map(fn (array $i) => [
                    'food_id' => $i['food']->id,
                    'quantity_grams' => 100,
                    'alternatives' => array_map(fn (Food $a) => ['food_id' => $a->id, 'quantity_grams' => 100], $i['alternatives'] ?? []),
                ], $items),
            ]],
        ], $this->token())->assertCreated()->json();
    }

    private function activate(int $planId, array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}/activate", $body, $this->token());
    }

    private function show(int $planId): TestResponse
    {
        return $this->getJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$planId}", $this->token())->assertOk();
    }

    // ---- matching ---------------------------------------------------------

    public function test_a_tagged_food_conflicting_with_an_approved_allergy_is_reported(): void
    {
        $this->allergy('peanuts');
        $peanut = $this->food(['name_ar' => 'زبدة الفول السوداني', 'allergens' => ['peanuts']]);

        $plan = $this->plan([['food' => $peanut]]);

        $conflict = $plan['allergen_conflicts'][0];
        $this->assertSame($peanut->id, $conflict['food_id']);
        $this->assertSame(['peanuts'], $conflict['groups']);
        $this->assertSame('tag', $conflict['match']);
        $this->assertSame('confirmed_allergy', $conflict['severity']);
        $this->assertSame('breakfast', $conflict['meal_name']);
        $this->assertSame('زبدة الفول السوداني', $conflict['food_name_ar']);
        $this->assertNull($conflict['parent_item_id']);
        $this->assertNotNull($conflict['item_id']);
        $this->assertNotNull($conflict['meal_id']);
    }

    public function test_an_other_allergy_matches_the_foods_name_ignoring_spelling(): void
    {
        $this->allergy('other', 'intolerance', 'ارز');
        $rice = $this->food(['name_ar' => 'أرز مسلوق']);

        $plan = $this->plan([['food' => $rice]]);

        $this->assertSame('text', $plan['allergen_conflicts'][0]['match']);
        $this->assertSame(['other'], $plan['allergen_conflicts'][0]['groups']);
        $this->assertSame('intolerance', $plan['allergen_conflicts'][0]['severity']);
    }

    public function test_an_other_allergy_also_matches_the_english_name(): void
    {
        $this->allergy('other', 'avoid', 'Kiwi');
        $kiwi = $this->food(['name_en' => 'Kiwi fruit, raw', 'name_ar' => null]);

        $this->assertCount(1, $this->plan([['food' => $kiwi]])['allergen_conflicts']);
    }

    public function test_alternatives_are_checked_too(): void
    {
        $this->allergy('milk_lactose');
        $safe = $this->food(['name_ar' => 'خبز']);
        $milk = $this->food(['name_ar' => 'حليب', 'allergens' => ['milk_lactose']]);

        $plan = $this->plan([['food' => $safe, 'alternatives' => [$milk]]]);

        $this->assertCount(1, $plan['allergen_conflicts']);
        $conflict = $plan['allergen_conflicts'][0];
        $this->assertSame($milk->id, $conflict['food_id']);
        $this->assertNotNull($conflict['parent_item_id'], 'an alternative reports the planned item it belongs to');
    }

    public function test_the_strongest_class_among_matched_allergies_wins(): void
    {
        $this->allergy('egg', 'avoid');
        $this->allergy('wheat_gluten', 'confirmed_allergy');
        $cake = $this->food(['name_ar' => 'كيك', 'allergens' => ['egg', 'wheat_gluten']]);

        $conflict = $this->plan([['food' => $cake]])['allergen_conflicts'][0];

        $this->assertSame('confirmed_allergy', $conflict['severity']);
        $this->assertEqualsCanonicalizing(['egg', 'wheat_gluten'], $conflict['groups']);
    }

    public function test_a_food_with_no_allergen_tags_is_listed_as_unverified_not_as_safe(): void
    {
        $this->allergy('peanuts');
        $untagged = $this->food(['name_ar' => 'صنف جديد']);
        DB::table('foods')->where('id', $untagged->id)->update(['allergens' => null]);

        $plan = $this->plan([['food' => $untagged]]);

        $this->assertSame([], $plan['allergen_conflicts']);
        $this->assertCount(1, $plan['unverified_items']);
        $this->assertSame($untagged->id, $plan['unverified_items'][0]['food_id']);
        $this->assertSame('صنف جديد', $plan['unverified_items'][0]['food_name_ar']);
    }

    /** A patient's own proposal has not been reviewed: it does not count. */
    public function test_a_pending_patient_proposal_is_ignored(): void
    {
        ProfileProposal::create([
            'subscriber_id' => $this->subscriber->id, 'kind' => 'allergy', 'action' => 'add',
            'payload' => ['group' => 'peanuts', 'class' => 'confirmed_allergy'],
        ]);
        $peanut = $this->food(['allergens' => ['peanuts']]);

        $this->assertSame([], $this->plan([['food' => $peanut]])['allergen_conflicts']);
    }

    public function test_no_allergies_means_no_conflicts(): void
    {
        $this->assertSame([], $this->plan([['food' => $this->food(['allergens' => ['peanuts']])]])['allergen_conflicts']);
    }

    // ---- activation guard -------------------------------------------------

    public function test_activating_over_a_confirmed_allergy_is_refused_without_acknowledgement(): void
    {
        $this->allergy('peanuts');
        $plan = $this->plan([['food' => $this->food(['allergens' => ['peanuts']])]]);

        $response = $this->activate($plan['id'])->assertStatus(422)->assertJsonPath('code', 'allergen_conflict_unacknowledged');

        $this->assertCount(1, $response->json('allergen_conflicts'));
        $this->assertSame('draft', MealPlan::find($plan['id'])->status);
    }

    public function test_activating_with_acknowledgement_succeeds_and_records_it(): void
    {
        $this->allergy('peanuts');
        $plan = $this->plan([['food' => $this->food(['allergens' => ['peanuts']])]]);

        $this->activate($plan['id'], ['acknowledge_allergen_conflicts' => true])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('new_unacknowledged_conflicts', false);

        $stored = MealPlan::find($plan['id']);
        $this->assertNotNull($stored->allergen_ack_at);
        $this->assertSame($this->nutritionist->id, $stored->allergen_ack_by);
        $this->assertCount(1, $stored->allergen_ack_snapshot);
        $this->assertSame('peanuts', $stored->allergen_ack_snapshot[0]['groups'][0]);
    }

    public function test_an_intolerance_or_a_food_to_avoid_never_blocks(): void
    {
        $this->allergy('egg', 'intolerance');
        $this->allergy('soy', 'avoid');
        $plan = $this->plan([['food' => $this->food(['allergens' => ['egg']])], ['food' => $this->food(['allergens' => ['soy']])]]);

        $this->assertCount(2, $plan['allergen_conflicts']);
        $this->activate($plan['id'])->assertOk()->assertJsonPath('status', 'active');
        $this->assertNull(MealPlan::find($plan['id'])->allergen_ack_at, 'nothing was acknowledged, so nothing is recorded');
    }

    public function test_an_alternative_alone_conflicting_with_a_confirmed_allergy_blocks(): void
    {
        $this->allergy('fish');
        $plan = $this->plan([['food' => $this->food(), 'alternatives' => [$this->food(['allergens' => ['fish']])]]]);

        $this->activate($plan['id'])->assertStatus(422)->assertJsonPath('code', 'allergen_conflict_unacknowledged');
    }

    public function test_a_clean_plan_activates_as_before(): void
    {
        $this->allergy('peanuts');
        $plan = $this->plan([['food' => $this->food(['allergens' => ['egg']])]]);

        $this->activate($plan['id'])->assertOk();
    }

    // ---- after activation -------------------------------------------------

    public function test_an_edit_that_introduces_a_new_confirmed_conflict_is_saved_and_flagged(): void
    {
        $this->allergy('peanuts');
        $this->allergy('fish');
        $peanut = $this->food(['allergens' => ['peanuts']]);
        $fish = $this->food(['allergens' => ['fish']]);
        $plan = $this->plan([['food' => $peanut]]);
        $this->activate($plan['id'], ['acknowledge_allergen_conflicts' => true])->assertOk();

        // Re-saving what was acknowledged is not new.
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $peanut->id, 'quantity_grams' => 250]]]],
        ], $this->token())->assertOk()->assertJsonPath('new_unacknowledged_conflicts', false);

        // A different offending food is: saved, but flagged.
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}", [
            'meals' => [['name' => 'breakfast', 'items' => [
                ['food_id' => $peanut->id, 'quantity_grams' => 250],
                ['food_id' => $fish->id, 'quantity_grams' => 100],
            ]]],
        ], $this->token())->assertOk()->assertJsonPath('new_unacknowledged_conflicts', true)
            ->assertJsonCount(2, 'allergen_conflicts');
    }

    public function test_an_allergy_approved_after_activation_is_flagged_on_read(): void
    {
        $peanut = $this->food(['allergens' => ['peanuts']]);
        $plan = $this->plan([['food' => $peanut]]);
        $this->activate($plan['id'])->assertOk();
        $this->show($plan['id'])->assertJsonPath('new_unacknowledged_conflicts', false);

        $this->allergy('peanuts');

        $this->show($plan['id'])->assertJsonPath('new_unacknowledged_conflicts', true)->assertJsonCount(1, 'allergen_conflicts');
        // The list the patient page loads says so too.
        $list = $this->getJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", $this->token())->assertOk()->json();
        $this->assertTrue($list[0]['new_unacknowledged_conflicts']);
    }

    public function test_acknowledging_clears_the_flag_without_reactivating_or_notifying(): void
    {
        $this->allergy('peanuts');
        $plan = $this->plan([['food' => $this->food(['allergens' => ['peanuts']])]]);
        $this->activate($plan['id'], ['acknowledge_allergen_conflicts' => true])->assertOk();

        $this->allergy('fish');
        $fish = $this->food(['allergens' => ['fish']]);
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $fish->id, 'quantity_grams' => 100]]]],
        ], $this->token())->assertOk()->assertJsonPath('new_unacknowledged_conflicts', true);

        $before = MealPlan::find($plan['id']);
        $notifications = PatientNotification::count();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/acknowledge-allergens", ['acknowledge_allergen_conflicts' => true], $this->token())
            ->assertOk()->assertJsonPath('new_unacknowledged_conflicts', false)->assertJsonPath('status', 'active');

        $after = MealPlan::find($plan['id']);
        $this->assertEquals($before->activated_at, $after->activated_at);
        $this->assertSame($notifications, PatientNotification::count(), 'no extra notification on acknowledgement');
        $this->assertSame($fish->id, $after->allergen_ack_snapshot[0]['food_id']);
    }

    public function test_acknowledging_requires_the_explicit_flag(): void
    {
        $plan = $this->plan([['food' => $this->food()]]);
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/acknowledge-allergens", [], $this->token())
            ->assertStatus(422)->assertJsonValidationErrors('acknowledge_allergen_conflicts');
    }

    // ---- where it is reported, and where it is not -------------------------

    public function test_every_nutritionist_response_carries_the_report(): void
    {
        $this->allergy('peanuts');
        $peanut = $this->food(['allergens' => ['peanuts']]);
        $plan = $this->plan([['food' => $peanut]]);
        $base = "/api/v1/clients/{$this->subscriber->id}/meal-plans";

        $this->assertArrayHasKey('allergen_conflicts', $plan);
        $this->assertArrayHasKey('unverified_items', $plan);
        $this->show($plan['id'])->assertJsonCount(1, 'allergen_conflicts');
        $this->putJson("{$base}/{$plan['id']}", ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $peanut->id, 'quantity_grams' => 50]]]]], $this->token())
            ->assertOk()->assertJsonCount(1, 'allergen_conflicts');
        $this->patchJson("{$base}/{$plan['id']}/decision", ['approved_calories' => 1500], $this->token())
            ->assertOk()->assertJsonCount(1, 'allergen_conflicts');
        $this->postJson("{$base}/{$plan['id']}/duplicate", [], $this->token())->assertCreated()->assertJsonCount(1, 'allergen_conflicts');

        // The draft needs at least one safe food to build from.
        $this->food(['name_ar' => 'دجاج مشوي', 'calories_per_100g' => 200]);
        $draft = $this->postJson("{$base}/ai-draft", [], $this->token())->assertCreated();
        $this->assertArrayHasKey('allergen_conflicts', $draft->json());
        $this->assertSame([], $draft->json('allergen_conflicts'), 'the draft itself avoids the allergen');
    }

    public function test_a_plan_made_from_a_template_is_checked_like_any_plan(): void
    {
        $this->allergy('peanuts');
        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $source = $this->postJson("/api/v1/clients/{$other->id}/meal-plans", [
            'meals' => [['name' => 'breakfast', 'items' => [['food_id' => $this->food(['allergens' => ['peanuts']])->id, 'quantity_grams' => 80]]]],
        ], $this->token())->assertCreated()->json();
        $template = $this->postJson("/api/v1/clients/{$other->id}/meal-plans/{$source['id']}/save-as-template", ['name' => 'قالب'], $this->token())->assertCreated()->json();

        $applied = $this->postJson("/api/v1/meal-plan-templates/{$template['id']}/apply/{$this->subscriber->id}", [], $this->token())->assertCreated()->json();

        $this->show($applied['id'])->assertJsonCount(1, 'allergen_conflicts');
    }

    public function test_the_patient_never_receives_any_of_it(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);
        $this->allergy('peanuts');
        $peanut = $this->food(['allergens' => ['peanuts']]);
        $plan = $this->plan([['food' => $peanut]]);
        $this->activate($plan['id'], ['acknowledge_allergen_conflicts' => true])->assertOk();

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertOk();

        foreach (['allergen_conflicts', 'unverified_items', 'new_unacknowledged_conflicts', 'allergen_ack_at', 'allergen_ack_by', 'allergen_ack_snapshot'] as $field) {
            $this->assertArrayNotHasKey($field, $mine->json());
        }
        $this->assertStringNotContainsString('allergen_ack', $mine->getContent());

        // Food responses for the patient app are unchanged: no verified flag.
        $search = $this->getJson('/api/v1/foods/search?q='.urlencode((string) $peanut->name_en), $this->bearerFor($client));
        if ($search->status() === 200 && isset($search->json('data')[0])) {
            $this->assertArrayNotHasKey('allergens_verified', $search->json('data.0'));
        }
    }

    public function test_the_food_search_tells_the_nutritionist_which_foods_are_unverified(): void
    {
        $tagged = $this->food(['name_en' => 'Zzzlentil tagged']);
        $untagged = $this->food(['name_en' => 'Zzzlentil untagged']);
        DB::table('foods')->where('id', $untagged->id)->update(['allergens' => null]);

        $rows = collect($this->getJson('/api/v1/foods/search?q=Zzzlentil', $this->token())->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($rows[$tagged->id]['allergens_verified']);
        $this->assertFalse($rows[$untagged->id]['allergens_verified']);
        $this->assertSame([], $rows[$untagged->id]['allergens']);
    }

    // ---- the inline check -------------------------------------------------

    public function test_the_inline_check_uses_the_same_rules(): void
    {
        $this->allergy('peanuts', 'intolerance');
        $this->allergy('other', 'confirmed_allergy', 'ذرة');
        $peanut = $this->food(['allergens' => ['peanuts']]);
        $corn = $this->food(['name_ar' => 'ذرة حلوة']);
        $clean = $this->food(['name_ar' => 'خيار']);
        $untagged = $this->food(['name_ar' => 'جديد']);
        DB::table('foods')->where('id', $untagged->id)->update(['allergens' => null]);

        $rows = collect($this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", [
            'food_ids' => [$peanut->id, $corn->id, $clean->id, $untagged->id],
        ], $this->token())->assertOk()->json('foods'))->keyBy('food_id');

        $this->assertSame('intolerance', $rows[$peanut->id]['conflict']['severity']);
        $this->assertSame('confirmed_allergy', $rows[$corn->id]['conflict']['severity']);
        $this->assertNull($rows[$clean->id]['conflict']);
        $this->assertTrue($rows[$untagged->id]['unverified']);
        $this->assertFalse($rows[$clean->id]['unverified']);
    }

    public function test_another_nutritionist_cannot_use_the_check_or_acknowledge(): void
    {
        $plan = $this->plan([['food' => $this->food()]]);
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [1]], $stranger)->assertNotFound();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/acknowledge-allergens", ['acknowledge_allergen_conflicts' => true], $stranger)->assertNotFound();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $stranger)->assertNotFound();
    }
}
