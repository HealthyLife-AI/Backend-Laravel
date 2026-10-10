<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B13: the no-log alert counts from the later of the first sign-in and the
 * day the current plan took effect; no active plan, or one that starts in
 * the future, means no alert at all (BR-14's «stopped logging» badge is a
 * separate rule and is not changed).
 */
class LoggingClockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function patient(array $attributes = []): Subscriber
    {
        return Subscriber::factory()->active()->create(array_merge(['nutritionist_id' => User::factory()->nutritionist()->create()->id], $attributes));
    }

    private function plan(Subscriber $s, array $attributes = []): MealPlan
    {
        return MealPlan::query()->forceCreate(array_merge(['subscriber_id' => $s->id, 'created_by' => $s->nutritionist_id, 'status' => 'active', 'activated_at' => now()->subDays(10)], $attributes));
    }

    private function noLog(Subscriber $s, bool $open = true): bool
    {
        app(AlertEvaluationService::class)->evaluate($s->fresh());

        return Alert::where('subscriber_id', $s->id)->where('type', Alert::TYPE_NO_LOG)->when($open, fn ($q) => $q->whereNull('resolved_at'))->exists();
    }

    public function test_a_new_patient_with_no_plan_gets_no_alert(): void
    {
        $this->assertFalse($this->noLog($this->patient(['created_at' => now()->subDays(20)])));
    }

    public function test_a_plan_activated_one_day_ago_is_quiet_and_four_days_ago_alerts(): void
    {
        $recent = $this->patient(['created_at' => now()->subDays(20)]);
        $this->plan($recent, ['activated_at' => now()->subDay()]);
        $this->assertFalse($this->noLog($recent));

        $lapsed = $this->patient(['created_at' => now()->subDays(20)]);
        $this->plan($lapsed, ['activated_at' => now()->subDays(4)]);
        $this->assertTrue($this->noLog($lapsed));
    }

    public function test_a_patient_who_joined_minutes_ago_is_quiet_even_with_an_old_plan(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20), 'activated_at' => now()->subMinutes(5)]);
        $this->plan($s);

        $this->assertFalse($this->noLog($s));
    }

    public function test_logged_then_lapsed_alerts_and_logging_again_resolves(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20), 'activated_at' => now()->subDays(20), 'last_logged_at' => now()->subDays(5)]);
        $this->plan($s);
        $this->assertTrue($this->noLog($s));

        $s->forceFill(['last_logged_at' => now()])->save();
        $this->assertFalse($this->noLog($s));
    }

    public function test_a_new_plan_during_a_lapse_restarts_the_clock(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20), 'activated_at' => now()->subDays(20), 'last_logged_at' => now()->subDays(6)]);
        $old = $this->plan($s, ['activated_at' => now()->subDays(15)]);
        $this->assertTrue($this->noLog($s));

        $old->forceFill(['status' => 'archived'])->save();
        $this->plan($s, ['activated_at' => now()]);
        $this->assertFalse($this->noLog($s), 'the open alert resolves: the nutritionist is already in touch');
    }

    public function test_removing_the_plan_resolves_an_open_alert(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20)]);
        $plan = $this->plan($s, ['activated_at' => now()->subDays(6)]);
        $this->assertTrue($this->noLog($s));

        $plan->forceFill(['status' => 'archived'])->save();
        $this->assertFalse($this->noLog($s));
    }

    public function test_a_plan_starting_in_the_future_does_not_start_the_clock(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20)]);
        $this->plan($s, ['activated_at' => now()->subDays(10), 'start_date' => now()->addDays(3)->toDateString()]);

        $this->assertFalse($this->noLog($s));
    }

    public function test_start_date_comes_before_activated_at(): void
    {
        $s = $this->patient(['created_at' => now()->subDays(20)]);
        // Handed over 10 days ago, but meant to start yesterday: only a day of logging expected so far.
        $this->plan($s, ['activated_at' => now()->subDays(10), 'start_date' => now()->subDay()->toDateString()]);

        $this->assertFalse($this->noLog($s));
    }
}
