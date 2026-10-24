<?php

namespace Tests\Feature\Phase1;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientAllergy;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\ConditionCodes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 1, D: celiac disease (implies a confirmed gluten allergy) and fluid
 * retention (watched for sodium like hypertension).
 */
class CeliacFluidRetentionTest extends TestCase
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
            'activity_level' => 'sedentary', 'daily_calorie_needs' => 1800, 'health_conditions' => [],
        ]);
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function addCondition(string $code, array $extra = [])
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/conditions", ['code' => $code] + $extra, $this->token());
    }

    private function glutenAllergies()
    {
        return PatientAllergy::where('subscriber_id', $this->subscriber->id)->where('group', 'wheat_gluten');
    }

    private function plan(array $foods)
    {
        return $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", [
            'meals' => [['name' => 'lunch', 'items' => array_map(fn (Food $f) => ['food_id' => $f->id, 'quantity_grams' => 100], $foods)]],
        ], $this->token());
    }

    // ---- celiac -------------------------------------------------------------

    public function test_the_codes_exist_with_their_arabic_labels(): void
    {
        $this->assertSame('الداء البطني (السيلياك)', ConditionCodes::label('celiac'));
        $this->assertSame('احتباس السوائل', ConditionCodes::label('fluid_retention'));

        $available = collect($this->getJson("/api/v1/clients/{$this->subscriber->id}/conditions", $this->token())->assertOk()->json('available'))->pluck('label', 'code');
        $this->assertSame('الداء البطني (السيلياك)', $available['celiac']);
        $this->assertSame('احتباس السوائل', $available['fluid_retention']);
        $this->assertCount(12, $available);
    }

    public function test_celiac_adds_a_confirmed_gluten_allergy_once_and_removing_it_keeps_the_allergy(): void
    {
        $condition = $this->addCondition('celiac')->assertCreated();

        $allergy = $this->glutenAllergies()->sole();
        $this->assertSame('confirmed_allergy', $allergy->class);

        // The legacy mirrors follow, as for any allergy the nutritionist adds.
        $profile = $this->subscriber->healthProfile->fresh();
        $this->assertSame(['الداء البطني (السيلياك)'], $profile->health_conditions);
        $this->assertNotEmpty($profile->allergies);

        // Remove the condition and add it again: still ONE allergy, never removed.
        $this->deleteJson("/api/v1/clients/{$this->subscriber->id}/conditions/{$condition->json('id')}", [], $this->token())->assertNoContent();
        $this->assertSame(1, $this->glutenAllergies()->count(), 'removing the condition does not remove the allergy');

        $this->addCondition('celiac')->assertCreated();
        $this->assertSame(1, $this->glutenAllergies()->count());
    }

    public function test_an_existing_weaker_gluten_entry_is_raised_to_confirmed_and_a_confirmed_one_is_left(): void
    {
        PatientAllergy::create(['subscriber_id' => $this->subscriber->id, 'group' => 'wheat_gluten', 'class' => 'intolerance', 'note' => 'ملاحظة']);

        $this->addCondition('celiac')->assertCreated();

        $allergy = $this->glutenAllergies()->sole();
        $this->assertSame('confirmed_allergy', $allergy->class);
        $this->assertSame('ملاحظة', $allergy->note, 'only the class changes');
    }

    public function test_editing_a_condition_into_celiac_does_the_same(): void
    {
        $other = $this->addCondition('hypertension')->assertCreated();

        $this->putJson("/api/v1/clients/{$this->subscriber->id}/conditions/{$other->json('id')}", ['code' => 'celiac'], $this->token())->assertOk();

        $this->assertSame(1, $this->glutenAllergies()->count());
    }

    public function test_the_hard_allergen_warning_and_the_activation_acknowledgement_then_apply(): void
    {
        $this->addCondition('celiac')->assertCreated();
        $bread = Food::factory()->create(['name_ar' => 'خبز قمح', 'allergens' => ['wheat_gluten']]);
        $rice = Food::factory()->create(['allergens' => []]);

        $plan = $this->plan([$bread, $rice])->assertCreated();

        $conflicts = $plan->json('allergen_conflicts');
        $this->assertCount(1, $conflicts);
        $this->assertSame('confirmed_allergy', $conflicts[0]['severity']);
        $this->assertSame($bread->id, $conflicts[0]['food_id']);
        $this->assertSame(['wheat_gluten'], $conflicts[0]['groups']);

        $url = "/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan->json('id')}/activate";
        $this->postJson($url, [], $this->token())->assertStatus(422)->assertJsonPath('code', 'allergen_conflict_unacknowledged');
        $this->postJson($url, ['acknowledge_allergen_conflicts' => true], $this->token())->assertOk();
    }

    public function test_a_gluten_free_plan_has_no_conflict(): void
    {
        $this->addCondition('celiac')->assertCreated();

        $this->assertSame([], $this->plan([Food::factory()->create(['allergens' => []])])->assertCreated()->json('allergen_conflicts'));
    }

    // ---- fluid retention (and the sodium list) --------------------------------

    private function reviewedSalty(int $mg = 900): Food
    {
        return Food::factory()->create(['allergens' => [], 'sodium_mg_per_100g' => $mg, 'nutrition_reviewed_at' => now(), 'nutrition_review_source' => 'test']);
    }

    public function test_fluid_retention_gets_the_sodium_warning_like_hypertension(): void
    {
        $salty = $this->reviewedSalty(900);
        $mild = $this->reviewedSalty(300);

        // Nothing without the condition.
        $this->assertSame([], $this->plan([$salty])->json('nutrition_warnings'));

        $this->addCondition('fluid_retention')->assertCreated();

        $warnings = $this->plan([$salty, $mild])->assertCreated()->json('nutrition_warnings');
        $this->assertCount(1, $warnings);
        $this->assertSame('sodium', $warnings[0]['type']);
        $this->assertSame($salty->id, $warnings[0]['food_id']);

        // The food check (the picker) agrees.
        $check = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/allergen-check", ['food_ids' => [$salty->id, $mild->id]], $this->token())->assertOk()->json('foods');
        $this->assertSame(['sodium'], array_column($check[0]['nutrition_warnings'], 'type'));
        $this->assertSame([], $check[1]['nutrition_warnings']);
    }

    public function test_kidney_disease_and_hypertension_are_on_the_default_list_and_it_follows_the_config(): void
    {
        $this->assertSame(['hypertension', 'fluid_retention', 'kidney_disease'], config('nutrition.sodium_conditions'));

        $salty = $this->reviewedSalty(900);
        $this->addCondition('kidney_disease')->assertCreated();
        $this->assertCount(1, $this->plan([$salty])->json('nutrition_warnings'));

        config(['nutrition.sodium_conditions' => ['hypertension']]);
        $this->assertSame([], $this->plan([$salty])->json('nutrition_warnings'), 'taken off the list: no warning');
    }

    public function test_a_condition_outside_the_list_gets_no_sodium_warning(): void
    {
        $this->addCondition('celiac')->assertCreated();

        $this->assertSame([], $this->plan([$this->reviewedSalty(2000)])->json('nutrition_warnings'));
    }

    // ---- tips ----------------------------------------------------------------

    public function test_the_ready_made_tips_exist_for_both(): void
    {
        $this->addCondition('celiac')->assertCreated();
        $this->addCondition('fluid_retention')->assertCreated();

        $tips = collect($this->getJson("/api/v1/clients/{$this->subscriber->id}/patient-tips", $this->token())->assertOk()->json('conditions'))->keyBy('code');

        $this->assertCount(3, $tips['celiac']['tips']);
        $this->assertSame('تجنب القمح والشعير والجاودار وكل ما يُصنع منها، واقرأ الملصق الغذائي لكل منتج جاهز.', $tips['celiac']['tips'][0]);
        $this->assertCount(3, $tips['fluid_retention']['tips']);
        $this->assertSame('حرّك ساقيك وارفعهما عند الجلوس لفترات طويلة.', $tips['fluid_retention']['tips'][2]);
    }

    // ---- map-conditions ---------------------------------------------------------

    public function test_map_conditions_recognises_the_new_keywords(): void
    {
        $texts = [
            'مريضة سيلياك' => 'celiac',
            'حساسية القمح' => 'celiac',
            'الداء البطني' => 'celiac',
            'احتباس سوائل' => 'fluid_retention',
            'وذمة في القدمين' => 'fluid_retention',
            // "تورم" contains "ورم": it must still be fluid retention, not cancer.
            'تورم الساقين' => 'fluid_retention',
            'ورم في الثدي' => 'cancer',
        ];

        foreach ($texts as $text => $code) {
            $this->assertSame($code, ConditionCodes::fromText($text)['code'], $text);
        }
    }

    public function test_map_conditions_apply_gives_a_mapped_celiac_its_allergy(): void
    {
        $this->subscriber->healthProfile->update(['health_conditions' => ['سيلياك', 'احتباس سوائل']]);

        $this->artisan('health-records:map-conditions')->assertSuccessful();
        $this->assertSame(0, PatientCondition::count(), 'a dry run writes nothing');
        $this->assertSame(0, $this->glutenAllergies()->count());

        $this->artisan('health-records:map-conditions --apply')->assertSuccessful();

        $this->assertSame(['celiac', 'fluid_retention'], PatientCondition::where('subscriber_id', $this->subscriber->id)->orderBy('id')->pluck('code')->all());
        $this->assertSame('confirmed_allergy', $this->glutenAllergies()->sole()->class);
    }

    public function test_the_client_role_cannot_add_either(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/conditions", ['code' => 'celiac'], $this->bearerFor($client))->assertForbidden();
        $this->assertSame(0, $this->glutenAllergies()->count());
    }
}
