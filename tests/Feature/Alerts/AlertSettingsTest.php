<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\SubscriberAlertSetting;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\Scheduling\SelfScheduler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Pilot feedback 2, B: alert thresholds set per patient by their own
 * nutritionist. NULL = the global default; an override changes the alert
 * rules for that patient only, and never the adherence score.
 */
class AlertSettingsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->active()->withPlanInForce()->create(['nutritionist_id' => $this->nutritionist->id, 'goal' => 'weight_loss']);
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'male', 'activity_level' => 'sedentary', 'daily_calorie_needs' => 2000]);
    }

    private function url(?Subscriber $s = null): string
    {
        return '/api/v1/clients/'.($s ?? $this->subscriber)->id.'/alert-settings';
    }

    private function token(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function noLogOpen(): bool
    {
        return Alert::query()->where('subscriber_id', $this->subscriber->id)->where('type', Alert::TYPE_NO_LOG)->whereNull('resolved_at')->exists();
    }

    // ---- reading and writing ----------------------------------------------

    public function test_it_reports_effective_overrides_and_defaults(): void
    {
        $this->getJson($this->url(), $this->token())->assertOk()
            ->assertJsonPath('defaults.no_log_days', 3)
            ->assertJsonPath('defaults.calorie_exceeded_streak_days', 3)
            ->assertJsonPath('defaults.milestone_weight_change_kg', 2)
            ->assertJsonPath('defaults.milestone_window_days', 14)
            ->assertJsonPath('overrides.no_log_days', null)
            ->assertJsonPath('effective.no_log_days', 3);
    }

    public function test_the_defaults_follow_the_config(): void
    {
        config(['adherence.late_after_days' => 5, 'alerts.milestone_window_days' => 21]);

        $this->getJson($this->url(), $this->token())->assertOk()
            ->assertJsonPath('defaults.no_log_days', 5)
            ->assertJsonPath('effective.milestone_window_days', 21);
    }

    public function test_a_subset_is_saved_an_absent_field_is_left_alone_and_null_resets(): void
    {
        $this->putJson($this->url(), ['no_log_days' => 1, 'milestone_weight_change_kg' => 1.5], $this->token())->assertOk()
            ->assertJsonPath('overrides.no_log_days', 1)
            ->assertJsonPath('effective.no_log_days', 1)
            ->assertJsonPath('overrides.milestone_weight_change_kg', 1.5);

        // Only the streak is sent: the two above stay as they were.
        $this->putJson($this->url(), ['calorie_exceeded_streak_days' => 5], $this->token())->assertOk()
            ->assertJsonPath('overrides.no_log_days', 1)
            ->assertJsonPath('overrides.calorie_exceeded_streak_days', 5)
            ->assertJsonPath('overrides.milestone_weight_change_kg', 1.5);

        // null resets one field to the default and leaves the others.
        $this->putJson($this->url(), ['no_log_days' => null], $this->token())->assertOk()
            ->assertJsonPath('overrides.no_log_days', null)
            ->assertJsonPath('effective.no_log_days', 3)
            ->assertJsonPath('overrides.calorie_exceeded_streak_days', 5);

        $this->assertSame($this->nutritionist->id, SubscriberAlertSetting::query()->where('subscriber_id', $this->subscriber->id)->value('updated_by'));
    }

    public function test_validation_bounds(): void
    {
        foreach ([
            ['no_log_days' => 0], ['no_log_days' => 15], ['no_log_days' => 1.5],
            ['calorie_exceeded_streak_days' => 0], ['calorie_exceeded_streak_days' => 15],
            ['milestone_weight_change_kg' => 0.4], ['milestone_weight_change_kg' => 20.1], ['milestone_weight_change_kg' => 1.25],
            ['milestone_window_days' => 6], ['milestone_window_days' => 91],
        ] as $bad) {
            $this->putJson($this->url(), $bad, $this->token())->assertStatus(422)->assertJsonValidationErrors(array_key_first($bad));
        }

        foreach ([['no_log_days' => 1], ['no_log_days' => 14], ['milestone_weight_change_kg' => 0.5], ['milestone_weight_change_kg' => 20.0], ['milestone_window_days' => 7], ['milestone_window_days' => 90]] as $good) {
            $this->putJson($this->url(), $good, $this->token())->assertOk();
        }
    }

    // ---- who may touch it -------------------------------------------------

    public function test_another_nutritionist_cannot_read_or_change_it(): void
    {
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->getJson($this->url(), $stranger)->assertNotFound();
        $this->putJson($this->url(), ['no_log_days' => 1], $stranger)->assertNotFound();
        $this->assertSame(0, SubscriberAlertSetting::count());
    }

    public function test_the_patient_cannot_read_or_change_their_own(): void
    {
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber->update(['user_id' => $client->id]);

        $this->getJson($this->url(), $this->bearerFor($client))->assertForbidden();
        $this->putJson($this->url(), ['no_log_days' => 1], $this->bearerFor($client))->assertForbidden();
        $this->assertSame(0, SubscriberAlertSetting::count());
    }

    public function test_it_cannot_be_changed_once_follow_up_has_ended(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/archive", [], $this->token())->assertOk();

        $this->putJson($this->url(), ['no_log_days' => 1], $this->token())->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson($this->url(), $this->token())->assertOk();
    }

    // ---- the overrides really change the rules ----------------------------

    /** A 1-day threshold opens the alert on the save itself; raising it resolves it. */
    public function test_the_no_log_override_opens_and_resolves_the_alert_at_once(): void
    {
        $this->subscriber->forceFill(['last_logged_at' => now()->subDays(2)])->save();

        // Two days without a log: inside the default 3.
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertFalse($this->noLogOpen());

        $this->putJson($this->url(), ['no_log_days' => 1], $this->token())->assertOk();
        $this->assertTrue($this->noLogOpen(), 'the save evaluated this patient immediately');
        $this->assertSame('No meal logged in 1+ days.', Alert::query()->where('subscriber_id', $this->subscriber->id)->where('type', Alert::TYPE_NO_LOG)->value('message'));

        $this->putJson($this->url(), ['no_log_days' => 5], $this->token())->assertOk();
        $this->assertFalse($this->noLogOpen(), 'raising the threshold resolved it');

        // Resetting returns to the default, under which two days is fine.
        $this->putJson($this->url(), ['no_log_days' => null], $this->token())->assertOk();
        $this->assertFalse($this->noLogOpen());
    }

    public function test_an_override_applies_to_that_patient_only(): void
    {
        $other = Subscriber::factory()->active()->withPlanInForce()->create(['nutritionist_id' => $this->nutritionist->id]);
        foreach ([$this->subscriber, $other] as $s) {
            $s->forceFill(['last_logged_at' => now()->subDays(2)])->save();
        }

        $this->putJson($this->url(), ['no_log_days' => 1], $this->token())->assertOk();
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertTrue($this->noLogOpen());
        $this->assertFalse(Alert::query()->where('subscriber_id', $other->id)->where('type', Alert::TYPE_NO_LOG)->exists());
    }

    public function test_the_calorie_streak_override_changes_when_that_alert_fires(): void
    {
        $food = Food::factory()->create(['calories_per_100g' => 2500]);
        foreach ([1, 2] as $daysAgo) {
            MealLog::create(['subscriber_id' => $this->subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'logged_at' => now()->subDays($daysAgo)->setTime(12, 0)]);
        }
        $open = fn () => Alert::query()->where('subscriber_id', $this->subscriber->id)->where('type', Alert::TYPE_CALORIES_EXCEEDED)->whereNull('resolved_at')->exists();

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertFalse($open(), 'two days over target is short of the default 3');

        $this->putJson($this->url(), ['calorie_exceeded_streak_days' => 2], $this->token())->assertOk();
        $this->assertTrue($open());
        $this->assertSame('Daily calorie target exceeded for 2 consecutive days.', Alert::query()->where('type', Alert::TYPE_CALORIES_EXCEEDED)->value('message'));

        $this->putJson($this->url(), ['calorie_exceeded_streak_days' => 4], $this->token())->assertOk();
        $this->assertFalse($open());
    }

    public function test_the_milestone_overrides_change_the_threshold_and_window(): void
    {
        $this->subscriber->bodyCompositionReadings()->create(['recorded_at' => now()->subDays(13)->toDateString(), 'weight_kg' => 80, 'source' => 'self-reported']);
        $this->subscriber->bodyCompositionReadings()->create(['recorded_at' => now()->toDateString(), 'weight_kg' => 79, 'source' => 'self-reported']);
        $milestones = fn () => Alert::query()->where('subscriber_id', $this->subscriber->id)->where('type', Alert::TYPE_MILESTONE)->count();

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertSame(0, $milestones(), '1 kg is under the default 2 kg');

        $this->putJson($this->url(), ['milestone_weight_change_kg' => 1.0], $this->token())->assertOk();
        $this->assertSame(1, $milestones());
        $this->assertStringContainsString('over the last 14 days', Alert::query()->where('type', Alert::TYPE_MILESTONE)->value('message'));
    }

    /** The override is about alerts and the "stopped logging" status, never the score. */
    public function test_adherence_scoring_is_unchanged_by_an_override(): void
    {
        $this->subscriber->forceFill(['last_logged_at' => now()->subDays(2)])->save();
        $adherence = app(AdherenceService::class);

        $before = $adherence->summary($this->subscriber);

        $this->putJson($this->url(), ['no_log_days' => 1], $this->token())->assertOk();
        $after = $adherence->summary($this->subscriber->fresh());

        foreach (['adherence_percent', 'total_logs', 'on_plan_logs', 'off_plan_logs'] as $key) {
            $this->assertSame($before[$key], $after[$key], "{$key} must not move");
        }
        $this->assertSame($before['previous']['adherence_percent'], $after['previous']['adherence_percent']);
        $this->assertSame($before['change_pp'] ?? null, $after['change_pp'] ?? null);
    }

    /** The home "stopped logging" status and the alert agree about the one patient. */
    public function test_the_stopped_logging_status_follows_the_same_threshold(): void
    {
        // Logged two days ago, so the score has something to measure and only
        // the staleness threshold decides the status.
        MealLog::create(['subscriber_id' => $this->subscriber->id, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'logged_at' => now()->subDays(2)]);
        $this->subscriber->forceFill(['last_logged_at' => now()->subDays(2)])->save();
        $adherence = app(AdherenceService::class);

        $this->assertSame(AdherenceService::STATUS_STABLE, $adherence->summary($this->subscriber)['status'], 'two days is inside the default 3');

        $this->putJson($this->url(), ['no_log_days' => 1], $this->token())->assertOk();

        $this->assertSame(AdherenceService::STATUS_STOPPED, $this->subscriber->fresh()->adherence_status, 'the save refreshed the stored status the home list reads');
        $this->assertSame(AdherenceService::STATUS_STOPPED, $adherence->summary($this->subscriber->fresh())['status']);

        $this->putJson($this->url(), ['no_log_days' => null], $this->token())->assertOk();
        $this->assertSame(AdherenceService::STATUS_STABLE, $this->subscriber->fresh()->adherence_status);
    }

    // ---- the command ------------------------------------------------------

    public function test_the_command_can_evaluate_one_patient_without_counting_as_the_daily_run(): void
    {
        $other = Subscriber::factory()->active()->withPlanInForce()->create(['nutritionist_id' => $this->nutritionist->id]);
        foreach ([$this->subscriber, $other] as $s) {
            $s->forceFill(['last_logged_at' => now()->subDays(5)])->save();
        }

        $this->artisan('alerts:evaluate', ['--subscriber' => $this->subscriber->id])
            ->expectsOutputToContain("Checked client {$this->subscriber->id}")
            ->assertSuccessful();

        $this->assertTrue($this->noLogOpen());
        $this->assertFalse(Alert::query()->where('subscriber_id', $other->id)->exists(), 'the other patient was not evaluated');
        $this->assertNull(app(SelfScheduler::class)->lastRan('alerts'), 'a one-patient run is not the daily run');
    }

    public function test_the_command_refuses_an_unknown_or_inactive_patient(): void
    {
        $this->artisan('alerts:evaluate', ['--subscriber' => 999999])->expectsOutputToContain('No active client with id 999999')->assertFailed();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/archive", [], $this->token())->assertOk();
        $this->artisan('alerts:evaluate', ['--subscriber' => $this->subscriber->id])->assertFailed();
    }

    public function test_the_full_run_still_records_the_daily_run(): void
    {
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertNotNull(app(SelfScheduler::class)->lastRan('alerts'));
    }
}
