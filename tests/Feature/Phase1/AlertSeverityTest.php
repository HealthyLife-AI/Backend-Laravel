<?php

namespace Tests\Feature\Phase1;

use App\Models\Alert;
use App\Models\BodyCompositionReading;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealLog;
use App\Models\PatientCondition;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 1, C: severity is decided server-side when an alert opens, from the
 * rules in config/alerts.php, and the alerts list puts important first.
 */
class AlertSeverityTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
    }

    private function patient(array $conditions = [], string $goal = 'weight_maintenance'): Subscriber
    {
        $subscriber = Subscriber::factory()->active()->withPlanInForce()->create(['nutritionist_id' => $this->nutritionist->id, 'goal' => $goal]);

        foreach ($conditions as $code) {
            PatientCondition::create(['subscriber_id' => $subscriber->id, 'code' => $code]);
        }

        return $subscriber;
    }

    /** A stale patient: the no-log alert opens. */
    private function noLog(Subscriber $subscriber): Alert
    {
        $subscriber->forceFill(['last_logged_at' => now()->subDays(5)])->save();
        app(AlertEvaluationService::class)->evaluate($subscriber->fresh());

        return Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->firstOrFail();
    }

    /** Three days over a 2000 kcal target: the calories alert opens. */
    private function caloriesExceeded(Subscriber $subscriber): Alert
    {
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        foreach ([1, 2, 3] as $daysAgo) {
            $food = Food::factory()->create(['calories_per_100g' => 2500]);
            MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'logged_at' => now()->subDays($daysAgo)->setTime(12, 0)]);
        }
        app(AlertEvaluationService::class)->evaluate($subscriber->fresh());

        return Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_CALORIES_EXCEEDED)->firstOrFail();
    }

    public function test_the_default_is_normal(): void
    {
        $this->assertSame('normal', $this->noLog($this->patient())->severity);
    }

    public function test_a_patient_on_the_watch_list_gets_important_no_log_and_calorie_alerts(): void
    {
        foreach (['diabetes_type1', 'diabetes_type2', 'gestational_diabetes', 'insulin_resistance', 'prediabetes', 'hypertension', 'kidney_disease'] as $code) {
            $this->assertSame('important', $this->noLog($this->patient([$code]))->severity, "no_log / {$code}");
        }

        $this->assertSame('important', $this->caloriesExceeded($this->patient(['hypertension']))->severity);
    }

    public function test_other_conditions_and_other_types_stay_normal(): void
    {
        $this->assertSame('normal', $this->noLog($this->patient(['cancer', 'celiac', 'other']))->severity);
        $this->assertSame('normal', $this->caloriesExceeded($this->patient(['liver_disease']))->severity);

        // A milestone is good news, whoever it is about.
        $subscriber = $this->patient(['diabetes_type2'], 'weight_loss');
        $subscriber->forceFill(['last_logged_at' => now()])->save();
        foreach ([[10, 90], [1, 85]] as [$daysAgo, $kg]) {
            BodyCompositionReading::create(['subscriber_id' => $subscriber->id, 'recorded_at' => now()->subDays($daysAgo)->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => $kg]);
        }
        app(AlertEvaluationService::class)->evaluate($subscriber->fresh());

        $this->assertSame('normal', Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_MILESTONE)->sole()->severity);
    }

    public function test_severity_follows_the_config_without_a_code_change(): void
    {
        config(['alerts.severity.important_conditions' => ['cancer']]);

        $this->assertSame('important', $this->noLog($this->patient(['cancer']))->severity);
        $this->assertSame('normal', $this->noLog($this->patient(['diabetes_type2']))->severity, 'no longer on the list');

        config(['alerts.severity.important_types' => ['calories_exceeded']]);

        $this->assertSame('normal', $this->noLog($this->patient(['cancer']))->severity, 'no_log no longer qualifies');
        $this->assertSame('important', $this->caloriesExceeded($this->patient(['cancer']))->severity);
    }

    public function test_severity_is_fixed_when_the_alert_opens(): void
    {
        $subscriber = $this->patient();
        $alert = $this->noLog($subscriber);

        PatientCondition::create(['subscriber_id' => $subscriber->id, 'code' => 'diabetes_type1']);
        app(AlertEvaluationService::class)->evaluate($subscriber->fresh());

        $this->assertSame('normal', $alert->fresh()->severity);
        $this->assertSame(1, Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->count());
    }

    // ---- the list -----------------------------------------------------------

    public function test_the_list_puts_important_first_then_newest_and_filters_by_severity(): void
    {
        $token = $this->bearerFor($this->nutritionist);
        $a = $this->patient();
        $b = $this->patient();
        $important = $this->patient(['diabetes_type2']);

        $oldNormal = $this->noLog($a);
        $oldNormal->forceFill(['created_at' => now()->subDays(3)])->save();
        $newNormal = $this->noLog($b);
        $newNormal->forceFill(['created_at' => now()->subHour()])->save();
        $oldImportant = $this->noLog($important);
        $oldImportant->forceFill(['created_at' => now()->subDays(5)])->save();

        $ids = collect($this->getJson('/api/v1/alerts', $token)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$oldImportant->id, $newNormal->id, $oldNormal->id], $ids);

        $this->getJson('/api/v1/alerts', $token)->assertJsonPath('data.0.severity', 'important')->assertJsonPath('data.1.severity', 'normal');

        $only = $this->getJson('/api/v1/alerts?severity=important', $token)->assertOk();
        $this->assertSame([$oldImportant->id], collect($only->json('data'))->pluck('id')->all());
        $this->assertCount(2, $this->getJson('/api/v1/alerts?severity=normal', $token)->json('data'));

        $this->getJson('/api/v1/alerts?severity=urgent', $token)->assertStatus(422)->assertJsonValidationErrors('severity');
    }

    public function test_another_nutritionist_never_sees_these_alerts(): void
    {
        $this->noLog($this->patient(['diabetes_type2']));
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->assertSame([], $this->getJson('/api/v1/alerts?severity=important', $stranger)->assertOk()->json('data'));
    }
}
