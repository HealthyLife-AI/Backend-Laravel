<?php

namespace Tests\Feature\Phase2;

use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 2, D: the BMR is stored beside daily_calorie_needs and shown to the
 * nutritionist as rest -> with activity -> target. The formula, and
 * daily_calorie_needs itself, are untouched.
 *
 * Reference: female, 35, 170 cm, 70 kg. BMR = 10x70 + 6.25x170 - 5x35 - 161 =
 * 1426.5 -> 1427; light activity (x1.375) -> needs 1961.
 */
class EnergyBreakdownTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id, 'goal' => 'weight_maintenance']);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
    }

    private function save(array $extra = [])
    {
        return $this->putJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $extra + [
            'weight_kg' => 70, 'height_cm' => 170, 'age' => 35, 'gender' => 'female', 'activity_level' => 'light',
        ], $this->nutritionistToken);
    }

    private function row(): object
    {
        return DB::table('health_profiles')->where('subscriber_id', $this->subscriber->id)->first();
    }

    // ---- stored -----------------------------------------------------------------------

    public function test_saving_the_profile_stores_the_bmr_and_leaves_the_needs_formula_alone(): void
    {
        $this->save()->assertSuccessful();

        $this->assertSame(1427, (int) $this->row()->bmr);
        $this->assertSame(1961, (int) $this->row()->daily_calorie_needs, 'bmr x multiplier, unrounded, as before');
    }

    public function test_each_activity_level_and_gender_gives_the_same_needs_as_before(): void
    {
        $calc = app(\App\Services\Nutrition\NutritionCalculatorService::class);

        foreach (['sedentary', 'light', 'moderate', 'active', 'very_active'] as $level) {
            foreach (['male', 'female'] as $gender) {
                $this->save(['gender' => $gender, 'activity_level' => $level])->assertSuccessful();

                $this->assertSame($calc->calculateDailyCalorieNeeds(70, 170, 35, $gender, $level), (int) $this->row()->daily_calorie_needs, "{$gender} {$level}");
                $this->assertSame($calc->calculateBmr(70, 170, 35, $gender), (int) $this->row()->bmr);
            }
        }
    }

    public function test_changing_the_activity_level_through_the_goal_updates_both(): void
    {
        $this->save()->assertSuccessful();

        $this->putJson("/api/v1/clients/{$this->subscriber->id}/goal", ['goal_type' => 'weight_maintenance', 'activity_level' => 'active'], $this->nutritionistToken)->assertOk();

        $this->assertSame(1427, (int) $this->row()->bmr, 'the BMR does not depend on activity');
        $this->assertSame((int) round(1426.5 * 1.725), (int) $this->row()->daily_calorie_needs);
    }

    // ---- exposure ----------------------------------------------------------------------------

    public function test_the_nutritionist_sees_it_in_the_profile_and_in_the_breakdown(): void
    {
        $this->save()->assertSuccessful();

        $profile = $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->nutritionistToken)->assertOk();

        $profile->assertJsonPath('bmr', 1427)->assertJsonPath('daily_calorie_needs', 1961)
            ->assertJsonPath('calorie_suggestion.bmr', 1427)->assertJsonPath('calorie_suggestion.activity_level', 'light')
            ->assertJsonPath('calorie_suggestion.maintenance', 1961);
        $this->assertEquals(1.375, $profile->json('calorie_suggestion.activity_multiplier'));
        $this->assertSame((int) round(1427 * 1.375), (int) round($profile->json('calorie_suggestion.bmr') * $profile->json('calorie_suggestion.activity_multiplier')), 'rest x multiplier lands on the needs');
    }

    public function test_the_plan_response_carries_the_breakdown_for_the_nutritionist(): void
    {
        $this->save()->assertSuccessful();
        $food = Food::factory()->create(['allergens' => []]);

        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]]], $this->nutritionistToken)->assertCreated();

        $plan->assertJsonPath('calorie_suggestion.bmr', 1427)->assertJsonPath('calorie_suggestion.activity_level', 'light');
    }

    public function test_the_patient_never_receives_the_bmr_or_the_breakdown(): void
    {
        $this->save()->assertSuccessful();
        $food = Food::factory()->create(['allergens' => []]);
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]]], $this->nutritionistToken)->json();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/activate", [], $this->nutritionistToken)->assertOk();

        foreach (['/api/v1/me/health-profile', '/api/v1/me/meal-plan'] as $url) {
            $body = $this->getJson($url, $this->clientToken)->assertOk()->getContent();

            foreach (['bmr', 'activity_multiplier', 'calorie_suggestion', 'maintenance'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "{$url} must not carry {$leak}");
            }
        }

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->clientToken)->assertForbidden();
    }

    public function test_a_profile_saved_before_the_column_shows_a_computed_bmr_without_writing_it(): void
    {
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 35, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1961]);
        $this->assertNull($this->row()->bmr);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->nutritionistToken)->assertOk()
            ->assertJsonPath('calorie_suggestion.bmr', 1427)->assertJsonPath('bmr', null);

        $this->assertNull($this->row()->bmr, 'reading never writes');
    }

    public function test_the_stored_bmr_wins_over_a_recomputation(): void
    {
        $this->save()->assertSuccessful();
        DB::table('health_profiles')->where('subscriber_id', $this->subscriber->id)->update(['bmr' => 1500]);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->nutritionistToken)->assertJsonPath('calorie_suggestion.bmr', 1500);
    }

    public function test_the_deficit_and_reason_text_inputs_are_still_there(): void
    {
        $this->save(['weight_kg' => 105])->assertSuccessful();
        PatientGoal::create(['subscriber_id' => $this->subscriber->id, 'goal_type' => 'weight_loss']);
        $this->subscriber->forceFill(['goal' => 'weight_loss'])->save();

        $suggestion = $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->nutritionistToken)->json('calorie_suggestion');

        $this->assertSame('obesity_2', $suggestion['bmi_class']);
        $this->assertSame(-650, $suggestion['adjustment']);
        $this->assertNotNull($suggestion['bmr']);
        $this->assertFalse($suggestion['clamped_to_floor']);
    }

    // ---- backfill --------------------------------------------------------------------------------------

    private function oldProfile(Subscriber $s, int $needs = 1961, ?int $bmr = null): void
    {
        DB::table('health_profiles')->insert([
            'subscriber_id' => $s->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 35, 'gender' => 'female', 'activity_level' => 'light',
            'daily_calorie_needs' => $needs, 'bmr' => $bmr, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_the_backfill_dry_run_writes_nothing(): void
    {
        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->oldProfile($this->subscriber);
        $this->oldProfile($other);
        $before = DB::table('health_profiles')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('health-profiles:backfill-bmr')
            ->expectsOutputToContain('Dry run: 2 profile(s) without a BMR')
            ->expectsOutputToContain('Nothing written')
            ->assertSuccessful();

        $this->assertSame($before, DB::table('health_profiles')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_apply_fills_only_the_missing_ones_and_touches_nothing_else(): void
    {
        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->oldProfile($this->subscriber, needs: 1961);
        $this->oldProfile($other, needs: 2222, bmr: 1500);
        $before = DB::table('health_profiles')->orderBy('id')->get();

        $this->artisan('health-profiles:backfill-bmr', ['--apply' => true])->expectsOutputToContain('1 updated')->assertSuccessful();

        $after = DB::table('health_profiles')->orderBy('id')->get();
        $this->assertSame(1427, (int) $after[0]->bmr);
        $this->assertSame(1500, (int) $after[1]->bmr, 'an existing BMR is left alone');

        foreach ([0, 1] as $i) {
            foreach ((array) $before[$i] as $column => $value) {
                if ($column === 'bmr') {
                    continue;
                }
                $this->assertEquals($value, ((array) $after[$i])[$column], "{$column} must not change");
            }
        }

        // Running it again finds nothing to do.
        $this->artisan('health-profiles:backfill-bmr')->expectsOutputToContain('0 profile(s) without a BMR')->assertSuccessful();
    }
}
