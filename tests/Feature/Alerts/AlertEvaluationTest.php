<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * S5-01 / FR-20: the three alert rules, evaluated directly against the
 * service (the command is a thin loop over this — see EvaluateAlertsTest
 * for the loop's own error-isolation behaviour).
 */
class AlertEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeActiveSubscriber(string $goal = 'weight_maintenance'): Subscriber
    {
        $nutritionist = User::factory()->nutritionist()->create();

        return Subscriber::factory()->active()->create([
            'nutritionist_id' => $nutritionist->id,
            'goal' => $goal,
        ]);
    }

    private function evaluate(Subscriber $subscriber): void
    {
        app(AlertEvaluationService::class)->evaluate($subscriber);
    }

    // --- no_log -------------------------------------------------------

    public function test_a_stale_client_gets_a_no_log_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        $subscriber->forceFill(['last_logged_at' => now()->subDays(4)])->save();

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', [
            'subscriber_id' => $subscriber->id,
            'type' => Alert::TYPE_NO_LOG,
            'resolved_at' => null,
        ]);
    }

    public function test_a_client_who_never_logged_gets_a_no_log_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG]);
    }

    public function test_a_recently_logged_client_gets_no_no_log_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG]);
    }

    /** Repeated runs while still stale must not spam a second open alert. */
    public function test_a_no_log_alert_is_not_duplicated_across_runs(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        $subscriber->forceFill(['last_logged_at' => now()->subDays(5)])->save();

        $this->evaluate($subscriber);
        $this->evaluate($subscriber);
        $this->evaluate($subscriber);

        $this->assertSame(1, Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->count());
    }

    /** The condition clearing must close the alert, not just leave it stale. */
    public function test_logging_again_resolves_the_open_no_log_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        $subscriber->forceFill(['last_logged_at' => now()->subDays(5)])->save();
        $this->evaluate($subscriber);
        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'resolved_at' => null]);

        $subscriber->forceFill(['last_logged_at' => now()])->save();
        $this->evaluate($subscriber);

        $alert = Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->sole();
        $this->assertNotNull($alert->resolved_at);
    }

    /** Going stale again after resolution must open a genuinely new alert. */
    public function test_a_no_log_alert_can_recur_after_resolving(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        $subscriber->forceFill(['last_logged_at' => now()->subDays(5)])->save();
        $this->evaluate($subscriber);
        $subscriber->forceFill(['last_logged_at' => now()])->save();
        $this->evaluate($subscriber);

        $subscriber->forceFill(['last_logged_at' => now()->subDays(5)])->save();
        $this->evaluate($subscriber);

        $this->assertSame(2, Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->count());
        $this->assertSame(1, Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_NO_LOG)->open()->count());
    }

    // --- calories_exceeded ---------------------------------------------

    private function logCalories(Subscriber $subscriber, int $daysAgo, float $calories): void
    {
        $food = Food::factory()->create(['calories_per_100g' => $calories]);
        MealLog::create([
            'subscriber_id' => $subscriber->id,
            'food_id' => $food->id,
            'quantity_grams' => 100,
            'logged_at' => now()->subDays($daysAgo)->setTime(12, 0),
        ]);
    }

    public function test_three_consecutive_days_over_target_fires_an_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);

        foreach ([1, 2, 3] as $daysAgo) {
            $this->logCalories($subscriber, $daysAgo, 2500);
        }

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', [
            'subscriber_id' => $subscriber->id,
            'type' => Alert::TYPE_CALORIES_EXCEEDED,
            'resolved_at' => null,
        ]);
    }

    /** Today's still-accumulating total must never count toward the streak. */
    public function test_todays_partial_total_is_excluded_from_the_streak(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);

        $this->logCalories($subscriber, 1, 2500);
        $this->logCalories($subscriber, 2, 2500);
        // Only 50 kcal logged so far today — would fail the streak if
        // counted, but must simply be ignored, not treated as day 3.
        MealLog::create([
            'subscriber_id' => $subscriber->id,
            'food_id' => Food::factory()->create(['calories_per_100g' => 50])->id,
            'quantity_grams' => 100,
            'logged_at' => now(),
        ]);
        $this->logCalories($subscriber, 3, 2500);

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED]);
    }

    /** A gap day breaks the streak — nothing was actually measured that day. */
    public function test_a_gap_day_breaks_the_streak(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);

        $this->logCalories($subscriber, 1, 2500);
        // day 2 (daysAgo=2) has no log at all
        $this->logCalories($subscriber, 3, 2500);
        $this->logCalories($subscriber, 4, 2500);

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED]);
    }

    public function test_a_client_under_target_gets_no_calorie_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);
        foreach ([1, 2, 3] as $daysAgo) {
            $this->logCalories($subscriber, $daysAgo, 500);
        }

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED]);
    }

    /** No health profile means no daily_calorie_needs — the rule cannot be evaluated at all. */
    public function test_no_health_profile_means_no_calorie_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        foreach ([1, 2, 3] as $daysAgo) {
            $this->logCalories($subscriber, $daysAgo, 5000);
        }

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED]);
    }

    /**
     * "Falling back" needs a genuinely new day under target, not a second
     * log added on top of an already-over-target day (that would only
     * raise the day's total further) — so this travels time forward
     * instead of adding more logs to the same three days.
     */
    public function test_falling_back_under_target_resolves_the_calorie_alert(): void
    {
        $subscriber = $this->makeActiveSubscriber();
        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);
        foreach ([1, 2, 3] as $daysAgo) {
            $this->logCalories($subscriber, $daysAgo, 2500);
        }
        $this->evaluate($subscriber);
        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_CALORIES_EXCEEDED, 'resolved_at' => null]);

        Carbon::setTestNow(now()->addDay());
        // Relative to the new "now", yesterday is a fresh day, logged under target.
        $this->logCalories($subscriber, 1, 500);
        $this->evaluate($subscriber);
        Carbon::setTestNow();

        $alert = Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_CALORIES_EXCEEDED)->sole();
        $this->assertNotNull($alert->resolved_at);
    }

    // --- milestone -------------------------------------------------------

    private function logWeight(Subscriber $subscriber, int $daysAgo, float $weightKg): void
    {
        $subscriber->bodyCompositionReadings()->create([
            'recorded_at' => now()->subDays($daysAgo)->toDateString(),
            'weight_kg' => $weightKg,
            'source' => 'self-reported',
        ]);
    }

    public function test_weight_loss_goal_fires_a_milestone_on_sufficient_drop(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_loss');
        $this->logWeight($subscriber, 13, 80.0);
        $this->logWeight($subscriber, 0, 77.5); // -2.5kg, over the 2kg default

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
    }

    public function test_weight_gain_goal_fires_a_milestone_on_sufficient_rise(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_gain');
        $this->logWeight($subscriber, 13, 60.0);
        $this->logWeight($subscriber, 0, 62.5);

        $this->evaluate($subscriber);

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
    }

    /** Moving the wrong direction for the goal is not a milestone, even if the magnitude qualifies. */
    public function test_weight_loss_goal_does_not_fire_on_a_gain(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_loss');
        $this->logWeight($subscriber, 13, 77.5);
        $this->logWeight($subscriber, 0, 80.0);

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
    }

    public function test_a_change_below_threshold_does_not_fire(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_loss');
        $this->logWeight($subscriber, 13, 80.0);
        $this->logWeight($subscriber, 0, 79.0); // -1kg, under the 2kg default

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
    }

    /** No target/band exists for these two goals — a real, documented gap, not an invented rule. */
    public function test_maintenance_and_monitoring_goals_never_fire_a_milestone(): void
    {
        foreach (['weight_maintenance', 'health_monitoring'] as $goal) {
            $subscriber = $this->makeActiveSubscriber($goal);
            $this->logWeight($subscriber, 13, 80.0);
            $this->logWeight($subscriber, 0, 70.0); // a huge swing either way

            $this->evaluate($subscriber);

            $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
        }
    }

    /** A sustained trend must not re-fire a new milestone on every single run. */
    public function test_a_sustained_trend_does_not_refire_within_the_window(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_loss');
        $this->logWeight($subscriber, 13, 80.0);
        $this->logWeight($subscriber, 0, 77.0);

        $this->evaluate($subscriber);
        $this->evaluate($subscriber);
        $this->evaluate($subscriber);

        $this->assertSame(1, Alert::where('subscriber_id', $subscriber->id)->where('type', Alert::TYPE_MILESTONE)->count());
    }

    public function test_a_single_reading_cannot_fire_a_milestone(): void
    {
        $subscriber = $this->makeActiveSubscriber('weight_loss');
        $this->logWeight($subscriber, 0, 70.0);

        $this->evaluate($subscriber);

        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE]);
    }
}
