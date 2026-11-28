<?php

namespace Tests\Feature\Audit\Isolation;

use App\Models\AiSummary;
use App\Models\Subscriber;
use App\Services\Scheduling\SelfScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Audit I1 (beyond HTTP). The request-driven SelfScheduler runs the daily bulk jobs inside an API request
 * (deferred, after the response) — while Auth::user() is still the caller. Subscriber's NutritionistScope then
 * narrows `Subscriber::active()` in EvaluateAlerts / SendLogReminders / GenerateWeeklySummaries to the CALLER's
 * clinic, and the task is marked as run for everyone. Expected: every clinic's patients are processed.
 */
class SelfSchedulerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_triggered_bulk_run_processes_every_clinic_not_only_the_callers(): void
    {
        $w = IsolationWorld::build();
        config(['scheduling.self_trigger' => true]);
        // Monday 10:00 clinic time: alerts (06:00), summaries (Mon 07:00), measurements/checkins (09:00) are all overdue.
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', config('scheduling.timezone')));
        $activeEverywhere = Subscriber::withoutGlobalScopes()->active()->count();

        $this->getJson('/api/v1/clients', ['Authorization' => 'Bearer '.$w->tokens['nutB']])->assertOk();

        $alerts = app(SelfScheduler::class)->lastResult('alerts');
        $this->assertNotNull($alerts, 'self-scheduler did not run');
        $this->assertNotNull(app(SelfScheduler::class)->lastRan('summaries'));
        $summarised = AiSummary::query()->where('week_start', '2026-11-16')->pluck('subscriber_id')->all();

        $this->assertSame($activeEverywhere, $alerts['patients'], 'alerts:evaluate, run from nutritionist B\'s request, only saw '.$alerts['patients']." of {$activeEverywhere} active patients");
        $this->assertContains($w->subs['A1']->id, $summarised, 'clinic A patient got no weekly summary: the run was scoped to nutritionist B, and is now marked done for the week');
    }

    /** Control: the same command with nobody logged in (real cron) sees every clinic. */
    public function test_control_cron_run_sees_every_clinic(): void
    {
        IsolationWorld::build();
        Auth::forgetUser();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', config('scheduling.timezone')));
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertSame(Subscriber::withoutGlobalScopes()->active()->count(), app(SelfScheduler::class)->lastResult('alerts')['patients']);
    }
}
