<?php

namespace Tests\Feature\Scheduling;

use App\Models\Alert;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Scheduling\SelfScheduler;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Alerts, reminders and weekly summaries run with no cron and no manual
 * command: any API request that finds a job overdue runs it after the
 * response, and saving new client data re-evaluates that client's alerts
 * immediately.
 */
class SelfSchedulerTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['scheduling.self_trigger' => true, 'scheduling.timezone' => 'Asia/Riyadh']);
    }

    /** Wednesday 2026-09-23, 10:00 in Riyadh (07:00 UTC). */
    private function wednesdayMorning(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-23 10:00', 'Asia/Riyadh')->utc();
    }

    public function test_every_job_is_overdue_on_a_fresh_system_except_reminders_outside_the_evening(): void
    {
        $scheduler = app(SelfScheduler::class);

        $this->assertSame(['alerts', 'summaries'], $scheduler->overdue($this->wednesdayMorning()));
    }

    public function test_slots_follow_the_clinic_timezone(): void
    {
        $scheduler = app(SelfScheduler::class);
        $now = $this->wednesdayMorning();

        $this->assertEquals(CarbonImmutable::parse('2026-09-23 03:00', 'UTC'), $scheduler->latestSlot('alerts', $now));
        $this->assertEquals(CarbonImmutable::parse('2026-09-22 17:00', 'UTC'), $scheduler->latestSlot('reminders', $now));
        $this->assertEquals(CarbonImmutable::parse('2026-09-21 04:00', 'UTC'), $scheduler->latestSlot('summaries', $now));
    }

    public function test_a_job_that_ran_after_its_slot_is_not_due_until_the_next_one(): void
    {
        $scheduler = app(SelfScheduler::class);
        $this->travelTo($this->wednesdayMorning());
        $scheduler->markRan('alerts');

        $this->assertFalse($scheduler->isDue('alerts', CarbonImmutable::now()->addHours(12)));
        // Thursday 06:00 Riyadh has passed.
        $this->assertTrue($scheduler->isDue('alerts', CarbonImmutable::parse('2026-09-24 06:05', 'Asia/Riyadh')->utc()));
    }

    public function test_missed_reminders_are_only_caught_up_the_same_evening(): void
    {
        $scheduler = app(SelfScheduler::class);

        $this->assertTrue($scheduler->isDue('reminders', CarbonImmutable::parse('2026-09-23 21:30', 'Asia/Riyadh')->utc()));
        $this->assertFalse($scheduler->isDue('reminders', CarbonImmutable::parse('2026-09-24 01:00', 'Asia/Riyadh')->utc()));
    }

    public function test_an_api_request_runs_overdue_jobs_with_no_cron(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $auth = $this->bearerFor($nutritionist); // before travelling: JWT expiry is checked against the real clock
        $this->travelTo($this->wednesdayMorning());
        $silent = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'last_logged_at' => null]);

        $this->getJson('/api/v1/alerts', $auth)->assertOk();

        $this->assertTrue($silent->alerts()->ofType(Alert::TYPE_NO_LOG)->exists(), 'alerts job did not run');
        $this->assertSame(1, $silent->aiSummaries()->count(), 'weekly summary job did not run');
        $this->assertNotNull(app(SelfScheduler::class)->lastRan('alerts'));
        $this->assertNotNull(app(SelfScheduler::class)->lastRan('summaries'));
    }

    public function test_jobs_are_not_repeated_by_later_requests_in_the_same_slot(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $auth = $this->bearerFor($nutritionist); // before travelling: JWT expiry is checked against the real clock
        $this->travelTo($this->wednesdayMorning());

        $this->getJson('/api/v1/alerts', $auth)->assertOk();
        $firstRun = app(SelfScheduler::class)->lastRan('alerts');

        $this->travel(2)->hours();
        Cache::forget('self-scheduler:throttle');
        $this->getJson('/api/v1/alerts', $auth)->assertOk();

        $this->assertEquals($firstRun, app(SelfScheduler::class)->lastRan('alerts'));
    }

    public function test_a_scheduled_command_run_counts_so_the_request_path_skips_it(): void
    {
        $this->travelTo($this->wednesdayMorning());

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertNotContains('alerts', app(SelfScheduler::class)->overdue());
    }

    public function test_it_does_nothing_when_disabled(): void
    {
        config(['scheduling.self_trigger' => false]);
        $nutritionist = User::factory()->nutritionist()->create();
        $auth = $this->bearerFor($nutritionist); // before travelling: JWT expiry is checked against the real clock
        $this->travelTo($this->wednesdayMorning());
        Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'last_logged_at' => null]);

        $this->getJson('/api/v1/alerts', $auth)->assertOk();

        $this->assertSame(0, Alert::count());
    }

    public function test_a_new_weight_reading_raises_a_milestone_immediately(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $auth = $this->bearerFor($nutritionist);

        // Jobs already ran today, so only the change-triggered path can create the alert.
        $this->travelTo($this->wednesdayMorning());
        foreach (array_keys(SelfScheduler::TASKS) as $task) {
            app(SelfScheduler::class)->markRan($task);
        }

        $subscriber = Subscriber::factory()->active()->create([
            'nutritionist_id' => $nutritionist->id, 'goal' => 'weight_loss', 'last_logged_at' => now(),
        ]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->subDays(10)->toDateString(), 'weight_kg' => 85,
        ], $auth)->assertCreated();

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->toDateString(), 'weight_kg' => 82.5,
        ], $auth)->assertCreated();

        $this->assertTrue($subscriber->alerts()->ofType(Alert::TYPE_MILESTONE)->exists());
    }
}
