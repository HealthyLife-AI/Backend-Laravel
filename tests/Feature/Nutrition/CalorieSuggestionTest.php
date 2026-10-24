<?php

namespace Tests\Feature\Nutrition;

use App\Models\Alert;
use App\Models\BodyCompositionReading;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealLog;
use App\Models\PatientCondition;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Nutrition\CalorieTarget;
use App\Support\Bmi;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Clinical batch 1, B: the goal-adjusted calorie suggestion. The deficits are
 * the pilot nutritionist's numbers (config/nutrition.php); maintenance
 * (daily_calorie_needs) is never changed by any of it.
 */
class CalorieSuggestionTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
    }

    /** Height 170 cm: 60 kg BMI 20.8, 80 kg 27.7, 95 kg 32.9, 105 kg 36.3, 120 kg 41.5, 50 kg 17.3. */
    private function patient(float $weight, string $goal = 'weight_loss', array $profile = [], array $conditions = []): Subscriber
    {
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

        HealthProfile::create($profile + [
            'subscriber_id' => $subscriber->id,
            'weight_kg' => $weight, 'height_cm' => 170, 'age' => 35, 'gender' => 'female',
            'activity_level' => 'light', 'daily_calorie_needs' => 2400,
        ]);
        PatientGoal::create(['subscriber_id' => $subscriber->id, 'goal_type' => $goal]);

        foreach ($conditions as $code) {
            PatientCondition::create(['subscriber_id' => $subscriber->id, 'code' => $code]);
        }

        return $subscriber->fresh();
    }

    // ---- BMI classes -----------------------------------------------------

    public function test_bmi_classes_split_obesity_and_keep_the_coarse_category(): void
    {
        $this->assertSame('overweight', Bmi::classify(27.0, 30));
        $this->assertSame('obesity_1', Bmi::classify(30.0, 30));
        $this->assertSame('obesity_1', Bmi::classify(34.9, 30));
        $this->assertSame('obesity_2', Bmi::classify(35.0, 30));
        $this->assertSame('obesity_3', Bmi::classify(40.0, 30));
        $this->assertNull(Bmi::classify(32.0, 17));

        // The old coarse band is untouched for existing callers.
        $this->assertSame('obesity', Bmi::category(38.0, 30));
        $this->assertSame('obesity', Bmi::coarse('obesity_2'));
    }

    // ---- deficits ----------------------------------------------------------

    public function test_each_bmi_class_gets_its_deficit(): void
    {
        foreach ([[80, 'overweight', -500], [95, 'obesity_1', -500], [105, 'obesity_2', -650], [120, 'obesity_3', -1000]] as [$kg, $class, $adjustment]) {
            $detail = CalorieTarget::suggestionDetail($this->patient($kg));

            $this->assertSame($class, $detail['bmi_class'], "{$kg} kg");
            $this->assertSame($adjustment, $detail['adjustment']);
            $this->assertSame(2400, $detail['maintenance']);
            $this->assertSame(2400 + $adjustment, $detail['suggested']);
            $this->assertNull($detail['reason']);
            $this->assertFalse($detail['clamped_to_floor']);
        }
    }

    public function test_normal_and_underweight_weight_loss_gets_no_deficit(): void
    {
        foreach ([60, 50] as $kg) {
            $detail = CalorieTarget::suggestionDetail($this->patient($kg));

            $this->assertSame(0, $detail['adjustment']);
            $this->assertSame(2400, $detail['suggested']);
            $this->assertSame('bmi_not_above_normal', $detail['reason']);
        }
    }

    public function test_conditions_block_any_automatic_adjustment(): void
    {
        foreach (['diabetes_type2', 'gestational_diabetes', 'kidney_disease', 'liver_disease', 'cancer', 'hypertension'] as $code) {
            $detail = CalorieTarget::suggestionDetail($this->patient(105, 'weight_loss', [], [$code]));

            $this->assertSame(0, $detail['adjustment'], $code);
            $this->assertSame(2400, $detail['suggested']);
            $this->assertSame('clinical_judgement_required', $detail['reason'], $code);
        }

        $type1 = CalorieTarget::suggestionDetail($this->patient(105, 'weight_loss', [], ['diabetes_type1']));
        $this->assertSame(0, $type1['adjustment']);
        $this->assertSame('type1_no_deficit', $type1['reason']);
    }

    public function test_unmapped_free_text_conditions_block_the_adjustment_until_structured(): void
    {
        $subscriber = $this->patient(105, 'weight_loss', ['health_conditions' => ['سكري']]);

        $detail = CalorieTarget::suggestionDetail($subscriber);
        $this->assertSame(0, $detail['adjustment']);
        $this->assertSame('clinical_judgement_required', $detail['reason']);

        // Once structured (a condition outside the blocking list), the deficit applies.
        PatientCondition::create(['subscriber_id' => $subscriber->id, 'code' => 'insulin_resistance']);
        $this->assertSame(-650, CalorieTarget::suggestionDetail($subscriber->fresh())['adjustment']);
    }

    public function test_a_condition_outside_the_list_does_not_block(): void
    {
        $detail = CalorieTarget::suggestionDetail($this->patient(105, 'weight_loss', [], ['insulin_resistance', 'other']));

        $this->assertSame(-650, $detail['adjustment']);
    }

    public function test_under_eighteen_gets_no_adjustment(): void
    {
        $detail = CalorieTarget::suggestionDetail($this->patient(105, 'weight_loss', ['age' => 16]));

        $this->assertSame(0, $detail['adjustment']);
        $this->assertSame('clinical_judgement_required', $detail['reason']);
        $this->assertNull($detail['bmi_class']);
    }

    public function test_a_gain_goal_adds_nothing_while_the_surplus_is_not_configured(): void
    {
        foreach (['weight_gain', 'muscle_gain'] as $goal) {
            $detail = CalorieTarget::suggestionDetail($this->patient(60, $goal));

            $this->assertSame(0, $detail['adjustment']);
            $this->assertSame('surplus_not_configured', $detail['reason']);
        }

        config(['nutrition.weight_gain_surplus' => 300]);
        $this->assertSame(2700, CalorieTarget::suggestionDetail($this->patient(60, 'weight_gain'))['suggested']);
    }

    public function test_other_goals_are_not_adjusted(): void
    {
        $detail = CalorieTarget::suggestionDetail($this->patient(105, 'weight_maintenance'));

        $this->assertSame(0, $detail['adjustment']);
        $this->assertNull($detail['reason']);
    }

    public function test_the_suggestion_never_goes_below_the_sex_specific_floor(): void
    {
        $woman = CalorieTarget::suggestionDetail($this->patient(120, 'weight_loss', ['daily_calorie_needs' => 1900]));
        $this->assertSame(1200, $woman['suggested']);
        $this->assertTrue($woman['clamped_to_floor']);
        $this->assertSame(-1000, $woman['adjustment']);

        $man = CalorieTarget::suggestionDetail($this->patient(120, 'weight_loss', ['gender' => 'male', 'daily_calorie_needs' => 2000]));
        $this->assertSame(1400, $man['suggested']);
        $this->assertTrue($man['clamped_to_floor']);

        $this->assertSame(1400, config('nutrition.calorie_floor.male'));
        $this->assertSame(1200, config('nutrition.calorie_floor.female'));
    }

    public function test_the_floor_applies_to_adults_of_known_gender_only(): void
    {
        $child = CalorieTarget::suggestionDetail($this->patient(60, 'weight_maintenance', ['age' => 12, 'daily_calorie_needs' => 1100]));
        $this->assertSame(1100, $child['suggested']);
        $this->assertFalse($child['clamped_to_floor']);

        // The column is NOT NULL, so "unknown" is simulated on the loaded model only.
        $unknown = $this->patient(60, 'weight_maintenance', ['daily_calorie_needs' => 1100]);
        $unknown->healthProfile->gender = null;
        $noGender = CalorieTarget::suggestionDetail($unknown);
        $this->assertSame(1100, $noGender['suggested']);
        $this->assertFalse($noGender['clamped_to_floor']);

        $woman = CalorieTarget::suggestionDetail($this->patient(60, 'weight_maintenance', ['daily_calorie_needs' => 1100]));
        $this->assertSame(1200, $woman['suggested']);
        $this->assertTrue($woman['clamped_to_floor']);

        $man = CalorieTarget::suggestionDetail($this->patient(60, 'weight_maintenance', ['gender' => 'male', 'daily_calorie_needs' => 1300]));
        $this->assertSame(1400, $man['suggested']);
        $this->assertTrue($man['clamped_to_floor']);
    }

    public function test_a_nutritionist_entered_bmi_wins_over_the_computed_one(): void
    {
        // Computed from 60 kg / 170 cm would be 20.8 (normal, no deficit); she entered 31.0.
        $subscriber = $this->patient(60);
        BodyCompositionReading::create(['subscriber_id' => $subscriber->id, 'recorded_at' => now()->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => 60, 'bmi' => 31.0]);

        $detail = CalorieTarget::suggestionDetail($subscriber->fresh());

        $this->assertSame(31.0, $detail['bmi']);
        $this->assertSame('obesity_1', $detail['bmi_class']);
        $this->assertSame(-500, $detail['adjustment']);
    }

    public function test_the_latest_weight_feeds_the_computed_bmi(): void
    {
        $subscriber = $this->patient(60);
        BodyCompositionReading::create(['subscriber_id' => $subscriber->id, 'recorded_at' => now()->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => 105]);

        $this->assertSame('obesity_2', CalorieTarget::suggestionDetail($subscriber->fresh())['bmi_class']);
    }

    public function test_no_profile_means_all_null(): void
    {
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

        $this->assertSame(
            ['maintenance' => null, 'bmr' => null, 'activity_level' => null, 'activity_multiplier' => null, 'adjustment' => null, 'suggested' => null, 'bmi' => null, 'bmi_class' => null, 'reason' => null, 'clamped_to_floor' => null],
            CalorieTarget::suggestionDetail($subscriber),
        );
        $this->assertNull(CalorieTarget::suggestion($subscriber));
    }

    // ---- consumers ---------------------------------------------------------

    public function test_effective_falls_back_to_the_adjusted_suggestion_and_the_approved_figure_still_wins(): void
    {
        $subscriber = $this->patient(105);

        $this->assertSame(1750, CalorieTarget::effective($subscriber));
        $this->assertSame(2400, $subscriber->healthProfile->daily_calorie_needs, 'maintenance is never rewritten');
    }

    public function test_the_calorie_exceeded_alert_uses_the_adjusted_target_without_an_approved_figure(): void
    {
        $subscriber = $this->patient(105);
        $subscriber->update(['goal' => 'weight_loss']);

        // 2100 kcal/day is under maintenance (2400) but over the adjusted 1750.
        foreach ([1, 2, 3] as $daysAgo) {
            $food = Food::factory()->create(['calories_per_100g' => 2100]);
            MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'logged_at' => now()->subDays($daysAgo)->setTime(12, 0)]);
        }

        app(AlertEvaluationService::class)->evaluate($subscriber->fresh());

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED, 'resolved_at' => null]);
    }

    public function test_activation_freezes_the_adjusted_suggestion(): void
    {
        $subscriber = $this->patient(105);
        $food = Food::factory()->create(['allergens' => []]);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->bearerFor($this->nutritionist))->assertCreated()->json();

        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->bearerFor($this->nutritionist))
            ->assertOk()->assertJsonPath('suggested_calories', 1750);
    }

    public function test_the_ai_draft_is_built_to_the_adjusted_target(): void
    {
        $subscriber = $this->patient(105);
        foreach (range(1, 12) as $i) {
            Food::factory()->create(['allergens' => [], 'calories_per_100g' => 100 + $i * 15]);
        }

        $adjusted = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", [], $this->bearerFor($this->nutritionist))->assertCreated();

        $total = collect($adjusted->json('summary_by_day'))->pluck('calories')->filter()->first()
            ?? collect($adjusted->json('summary_by_day'))->first()['total_calories'] ?? null;
        $this->assertNotNull($total, json_encode($adjusted->json('summary_by_day')));
        // Built to 1750, not to the 2400 maintenance figure.
        $this->assertLessThan(2100, $total);
    }

    // ---- exposure ----------------------------------------------------------

    public function test_the_breakdown_is_in_the_nutritionist_responses_only(): void
    {
        $subscriber = $this->patient(105);
        $client = User::factory()->client($this->nutritionist)->create();
        $subscriber->update(['user_id' => $client->id]);
        $food = Food::factory()->create(['allergens' => []]);

        $plan = $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans", [
            'meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]],
        ], $this->bearerFor($this->nutritionist))->assertCreated();

        $plan->assertJsonPath('calorie_suggestion.suggested', 1750)->assertJsonPath('calorie_suggestion.bmi_class', 'obesity_2');

        $this->getJson("/api/v1/clients/{$subscriber->id}/health-profile", $this->bearerFor($this->nutritionist))
            ->assertOk()->assertJsonPath('calorie_suggestion.adjustment', -650)->assertJsonPath('daily_calorie_needs', 2400);

        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan->json('id')}/activate", [], $this->bearerFor($this->nutritionist))->assertOk();

        $mine = $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($client))->assertOk();
        $this->assertArrayNotHasKey('calorie_suggestion', $mine->json());
        $this->assertStringNotContainsString('calorie_suggestion', $mine->getContent());

        $profile = $this->getJson('/api/v1/me/health-profile', $this->bearerFor($client));
        $this->assertStringNotContainsString('calorie_suggestion', $profile->getContent());
    }
}
