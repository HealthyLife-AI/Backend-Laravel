<?php

namespace Tests\Feature\Phase2;

use App\Models\BodyCompositionReading;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Support\ClinicDay;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 2, C: the goal projection. An estimate from config/projection.php
 * (7700 kcal/kg, 90/60/30% scenarios, caps), shown to the patient only when
 * the nutritionist switches it on, and never with a calorie number.
 *
 * Reference case: maintenance 2400, approved target 1900 (gap 500), so the
 * weekly change at full adherence is 500 x 7 / 7700 = 0.4545 kg.
 */
class GoalProjectionTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $otherClientToken;

    private array $strangerToken;

    private ?float $adherence = 75.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
        $otherClient = User::factory()->client($this->nutritionist)->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherClient->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($otherClient);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());

        // The patient's own adherence is set per test.
        $this->partialMock(AdherenceService::class, function ($mock) {
            $mock->shouldReceive('summary')->andReturnUsing(fn () => ['adherence_percent' => $this->adherence]);
        });
    }

    private function setUpPatient(string $goal = 'weight_loss', float $weight = 80, ?float $target = 70, int $maintenance = 2400, ?int $approved = 1900): void
    {
        HealthProfile::create([
            'subscriber_id' => $this->subscriber->id,
            'weight_kg' => $weight, 'height_cm' => 170, 'age' => 35, 'gender' => 'female',
            'activity_level' => 'light', 'daily_calorie_needs' => $maintenance,
        ]);
        PatientGoal::create(['subscriber_id' => $this->subscriber->id, 'goal_type' => $goal, 'target_weight_kg' => $target]);

        if ($approved !== null) {
            MealPlan::query()->forceCreate(['subscriber_id' => $this->subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'active', 'activated_at' => now(), 'approved_calories' => $approved]);
        }
    }

    private function reading(int $daysAgo, float $kg): void
    {
        BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => ClinicDay::today()->subDays($daysAgo)->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => $kg]);
    }

    private function projection(): ?array
    {
        return $this->getJson("/api/v1/clients/{$this->subscriber->id}/goal-projection", $this->nutritionistToken)->assertOk()->json('projection');
    }

    private function scenarios(array $projection): array
    {
        return collect($projection['scenarios'])->keyBy('level')->all();
    }

    private function show(bool $visible): void
    {
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/goal-projection-visibility", ['visible' => $visible], $this->nutritionistToken)->assertOk();
    }

    // ---- null cases -----------------------------------------------------------------------

    public function test_nothing_to_project_gives_null(): void
    {
        // No profile, no goal.
        $this->assertNull($this->projection());

        // A goal that is not about weight.
        $this->setUpPatient('weight_maintenance');
        $this->assertNull($this->projection());
    }

    public function test_no_target_weight_no_calorie_target_or_a_reached_goal_gives_null(): void
    {
        $this->setUpPatient(target: null);
        $this->assertNull($this->projection(), 'no target weight');

        PatientGoal::where('subscriber_id', $this->subscriber->id)->update(['target_weight_kg' => 80]);
        $this->assertNull($this->projection(), 'target equals the current weight');

        PatientGoal::where('subscriber_id', $this->subscriber->id)->update(['target_weight_kg' => 85]);
        $this->assertNull($this->projection(), 'a loss goal whose target is above the current weight is already met');
    }

    public function test_a_patient_without_a_health_profile_gives_null(): void
    {
        PatientGoal::create(['subscriber_id' => $this->subscriber->id, 'goal_type' => 'weight_loss', 'target_weight_kg' => 70]);

        $this->assertNull($this->projection());
    }

    // ---- scenario math ------------------------------------------------------------------------

    public function test_the_three_scenarios_follow_the_energy_gap(): void
    {
        $this->setUpPatient();
        $this->reading(0, 80);

        $p = $this->projection();
        $s = $this->scenarios($p);

        $this->assertEquals(10.0, $p['remaining_kg']);
        $this->assertSame('loss', $p['direction']);
        $this->assertSame(['high', 'medium', 'low'], array_column($p['scenarios'], 'level'));

        // weekly at full = 500 x 7 / 7700 = 0.4545; x0.9, x0.6, x0.3.
        $this->assertSame(25, $s['high']['weeks']);   // 10 / 0.4091 = 24.4
        $this->assertSame(37, $s['medium']['weeks']); // 10 / 0.2727 = 36.7
        $this->assertSame(74, $s['low']['weeks']);    // 10 / 0.1364 = 73.3
        $this->assertTrue($s['high']['reachable']);
        $this->assertSame(ClinicDay::today()->addWeeks(25)->toDateString(), $s['high']['estimated_date']);
        $this->assertSame(ClinicDay::today()->addWeeks(74)->toDateString(), $s['low']['estimated_date']);
        $this->assertEquals(0.45, $p['inputs']['weekly_change_full_kg']);
    }

    public function test_the_current_weight_is_the_latest_reading_else_the_profile_weight(): void
    {
        $this->setUpPatient();
        $this->assertEquals(10.0, $this->projection()['remaining_kg'], 'profile weight 80, target 70');

        $this->reading(10, 84);
        $this->reading(2, 78);
        $this->assertEquals(8.0, $this->projection()['remaining_kg'], 'the latest reading, 78');
        $this->assertEquals(78.0, $this->projection()['inputs']['current_weight_kg']);
    }

    public function test_the_weekly_change_is_capped(): void
    {
        // A 2000 kcal gap would be 1.82 kg a week; loss is capped at 1.0.
        $this->setUpPatient(maintenance: 3400, approved: 1400);
        $this->assertEquals(1.0, $this->projection()['inputs']['weekly_change_full_kg']);
        $this->assertSame(12, $this->scenarios($this->projection())['high']['weeks'], '10 kg at 0.9 kg a week');
    }

    public function test_gain_is_capped_lower_and_runs_the_other_way(): void
    {
        $this->setUpPatient('weight_gain', weight: 60, target: 66, maintenance: 2400, approved: 3000);

        $p = $this->projection();

        $this->assertSame('gain', $p['direction']);
        $this->assertEquals(6.0, $p['remaining_kg']);
        // 600 kcal gap = 0.545 kg a week, capped at 0.5.
        $this->assertEquals(0.5, $p['inputs']['weekly_change_full_kg']);
        $this->assertSame(14, $this->scenarios($p)['high']['weeks']); // 6 / 0.45
    }

    public function test_a_small_gap_makes_slow_scenarios_not_reachable(): void
    {
        // gap 100 -> 0.0909 kg a week: high 0.0818, medium 0.0545 (both >= 0.05), low 0.0273 (< 0.05).
        $this->setUpPatient(approved: 2300);
        $s = $this->scenarios($this->projection());

        $this->assertTrue($s['high']['reachable']);
        $this->assertTrue($s['medium']['reachable']);
        $this->assertFalse($s['low']['reachable']);
        $this->assertNull($s['low']['weeks']);
        $this->assertNull($s['low']['estimated_date']);
    }

    public function test_a_target_that_runs_against_the_goal_reaches_nothing(): void
    {
        // A weight-loss goal on a target ABOVE maintenance has no deficit to project from.
        $this->setUpPatient(approved: 2600);

        foreach ($this->scenarios($this->projection()) as $scenario) {
            $this->assertFalse($scenario['reachable']);
            $this->assertNull($scenario['weeks']);
        }
        $this->assertEquals(0, $this->projection()['inputs']['weekly_change_full_kg']);
    }

    // ---- current level ---------------------------------------------------------------------------

    public function test_the_current_level_is_the_scenario_nearest_the_patients_adherence(): void
    {
        $this->setUpPatient();

        $cases = [[100.0, 'high'], [80.0, 'high'], [76.0, 'high'], [75.0, 'medium'], [60.0, 'medium'], [46.0, 'medium'], [45.0, 'low'], [30.0, 'low'], [0.0, 'low']];

        foreach ($cases as [$percent, $level]) {
            $this->adherence = $percent;
            $this->assertSame($level, $this->projection()['current_level'], "{$percent}%");
        }

        $this->adherence = null;
        $this->assertNull($this->projection()['current_level'], 'no logs, no level');
        $this->assertNull($this->projection()['inputs']['adherence_percent']);
    }

    public function test_adherence_is_measured_over_the_last_fourteen_days(): void
    {
        $this->setUpPatient();
        $seen = null;
        $this->partialMock(AdherenceService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('summary')->andReturnUsing(function ($subscriber, $from, $to) use (&$seen) {
                $seen = [$from, $to];

                return ['adherence_percent' => 70.0];
            });
        });

        $this->projection();

        $this->assertSame([ClinicDay::today()->subDays(13)->toDateString(), ClinicDay::today()->toDateString()], $seen);
    }

    // ---- observed trend ------------------------------------------------------------------------------

    public function test_the_observed_trend_is_a_regression_over_enough_readings(): void
    {
        $this->setUpPatient();
        foreach ([[28, 82.0], [21, 81.5], [14, 81.0], [7, 80.5], [0, 80.0]] as [$days, $kg]) {
            $this->reading($days, $kg);
        }

        $this->assertEquals(-0.5, $this->projection()['observed_weekly_change_kg']);

        // A noisy series still gives the fitted slope, not the end-to-end one.
        BodyCompositionReading::query()->delete();
        foreach ([[28, 82.0], [20, 81.9], [10, 80.4], [0, 80.3]] as [$days, $kg]) {
            $this->reading($days, $kg);
        }
        $trend = $this->projection()['observed_weekly_change_kg'];
        $this->assertLessThan(0, $trend);
        $this->assertEqualsWithDelta(-0.4, $trend, 0.1);
    }

    public function test_too_few_readings_or_too_short_a_span_gives_no_trend(): void
    {
        $this->setUpPatient();
        foreach ([[20, 82.0], [10, 81.0], [0, 80.0]] as [$days, $kg]) {
            $this->reading($days, $kg);
        }
        $this->assertNull($this->projection()['observed_weekly_change_kg'], 'three readings');

        $this->reading(5, 80.5);
        $this->assertNull($this->projection()['observed_weekly_change_kg'], 'four readings but only 20 days');

        $this->reading(21, 82.5);
        $this->assertNotNull($this->projection()['observed_weekly_change_kg'], 'four-plus readings over 21 days');
    }

    public function test_readings_older_than_the_window_are_ignored_for_the_trend(): void
    {
        $this->setUpPatient();
        foreach ([[120, 95.0], [100, 94.0], [90, 92.0], [80, 90.0]] as [$days, $kg]) {
            $this->reading($days, $kg);
        }
        $this->reading(0, 80.0);

        $this->assertNull($this->projection()['observed_weekly_change_kg']);
    }

    public function test_the_trend_is_reported_but_never_drives_the_projection(): void
    {
        $this->setUpPatient();
        foreach ([[28, 90.0], [21, 86.0], [14, 83.0], [7, 81.0], [0, 80.0]] as [$days, $kg]) {
            $this->reading($days, $kg);
        }

        $p = $this->projection();

        $this->assertLessThan(-1.5, $p['observed_weekly_change_kg']);
        $this->assertSame(25, $this->scenarios($p)['high']['weeks'], 'still the energy model');
    }

    // ---- visibility and the patient's copy --------------------------------------------------------------

    public function test_it_is_hidden_from_the_patient_by_default(): void
    {
        $this->setUpPatient();
        $this->reading(0, 80);

        $this->assertSame('null', $this->getJson('/api/v1/me/goal-projection', $this->clientToken)->assertOk()->getContent());

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/goal-projection", $this->nutritionistToken)
            ->assertOk()->assertJsonPath('visible_to_patient', false)->assertJsonPath('projection.direction', 'loss');
    }

    public function test_when_shown_the_patient_gets_the_estimate_and_no_calorie_number(): void
    {
        $this->setUpPatient();
        $this->reading(0, 80);
        $this->adherence = 62.0;

        $this->show(true);
        $mine = $this->getJson('/api/v1/me/goal-projection', $this->clientToken)->assertOk();
        $json = $mine->json();

        $this->assertSame(['remaining_kg', 'direction', 'scenarios', 'current_level', 'observed_weekly_change_kg', 'disclaimer_ar'], array_keys($json));
        $this->assertSame('medium', $json['current_level']);
        $this->assertEquals(10.0, $json['remaining_kg']);
        $this->assertSame(['level', 'weeks', 'estimated_date', 'reachable'], array_keys($json['scenarios'][0]));
        $this->assertSame('تقدير تقريبي يعتمد على التزامك، والوزن يتغير يوميًا بسبب الماء والأملاح. أخصائيتك هي اللي بتقيّم تقدمك.', $json['disclaimer_ar']);

        $raw = strtolower($mine->getContent());
        foreach (['kcal', 'calorie', 'maintenance', 'target', 'inputs', '1900', '2400', 'adherence'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw, "the patient's copy must not carry: {$leak}");
        }

        $this->show(false);
        $this->assertSame('null', $this->getJson('/api/v1/me/goal-projection', $this->clientToken)->getContent());
    }

    public function test_when_shown_but_there_is_nothing_to_project_the_patient_still_gets_null(): void
    {
        $this->show(true);

        $this->assertSame('null', $this->getJson('/api/v1/me/goal-projection', $this->clientToken)->getContent());
    }

    public function test_the_patient_never_sees_the_nutritionists_view_or_anothers(): void
    {
        $this->setUpPatient();
        $this->show(true);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/goal-projection", $this->clientToken)->assertForbidden();
        $this->putJson("/api/v1/clients/{$this->subscriber->id}/goal-projection-visibility", ['visible' => false], $this->clientToken)->assertForbidden();
        $this->assertSame('null', $this->getJson('/api/v1/me/goal-projection', $this->otherClientToken)->getContent(), 'another patient sees their own: nothing');
        $this->getJson('/api/v1/me/goal-projection', $this->nutritionistToken)->assertForbidden();
    }

    public function test_the_visibility_switch_is_validated_owned_and_follows_up(): void
    {
        $url = "/api/v1/clients/{$this->subscriber->id}/goal-projection-visibility";

        $this->putJson($url, [], $this->nutritionistToken)->assertStatus(422)->assertJsonValidationErrors('visible');
        $this->putJson($url, ['visible' => 'maybe'], $this->nutritionistToken)->assertStatus(422);
        $this->putJson($url, ['visible' => true], $this->strangerToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/goal-projection", $this->strangerToken)->assertNotFound();
        $this->assertFalse((bool) $this->subscriber->fresh()->show_goal_projection);

        $this->putJson($url, ['visible' => true], $this->nutritionistToken)->assertOk()->assertJsonPath('visible_to_patient', true);
        $this->assertTrue((bool) $this->subscriber->fresh()->show_goal_projection);

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->putJson($url, ['visible' => false], $this->nutritionistToken)->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->assertTrue((bool) $this->subscriber->fresh()->show_goal_projection);
    }

    public function test_the_nutritionist_sees_the_inputs(): void
    {
        $this->setUpPatient();
        $this->reading(0, 80);
        $this->adherence = 70.0;

        $inputs = $this->projection()['inputs'];

        $this->assertEquals(80.0, $inputs['current_weight_kg']);
        $this->assertEquals(70.0, $inputs['target_weight_kg']);
        $this->assertSame(2400, $inputs['maintenance_kcal']);
        $this->assertSame(1900, $inputs['target_kcal']);
        $this->assertEquals(70.0, $inputs['adherence_percent']);
        $this->assertEquals(0.45, $inputs['weekly_change_full_kg']);
    }
}
