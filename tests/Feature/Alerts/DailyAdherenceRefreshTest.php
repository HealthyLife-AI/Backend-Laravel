<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use App\Services\Scheduling\SelfScheduler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The 06:00 run (`alerts:evaluate`) recomputes every active patient's
 * stored adherence status before evaluating alerts, so the roster, its
 * filters and the overview counts agree with the alerts the same morning
 * without the patient logging anything.
 */
class DailyAdherenceRefreshTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->food = Food::factory()->create();
    }

    private function patient(array $attributes = []): Subscriber
    {
        return Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create($attributes + ['adherence_status' => 'stable']);
    }

    /** One plan item for $subscriber, so logs can be on-plan (BR-9). */
    private function planItem(Subscriber $subscriber): int
    {
        $planId = DB::table('meal_plans')->insertGetId(['subscriber_id' => $subscriber->id, 'created_by' => $this->nutritionist->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('meal_items')->insertGetId(['meal_id' => $mealId, 'food_id' => $this->food->id, 'quantity_grams' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function log(Subscriber $subscriber, int $daysAgo, ?int $mealItemId): void
    {
        DB::table('meal_logs')->insert([
            'subscriber_id' => $subscriber->id, 'food_id' => $this->food->id, 'meal_item_id' => $mealItemId,
            'quantity_grams' => 100, 'logged_at' => now()->subDays($daysAgo)->setTime(12, 0),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function overview(): array
    {
        return $this->getJson('/api/v1/dashboard/overview', $this->bearerFor($this->nutritionist))->assertOk()->json();
    }

    private function filtered(string $adherence): array
    {
        return collect($this->getJson("/api/v1/clients?adherence={$adherence}", $this->bearerFor($this->nutritionist))->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    public function test_a_patient_who_stopped_logging_reads_stopped_after_the_daily_run(): void
    {
        $patient = $this->patient(['last_logged_at' => now()->subDays(4)]);
        $this->log($patient, 4, $this->planItem($patient));

        $before = $this->overview();
        $this->assertSame([1, 0], [$before['stable'], $before['stopped_logging']]);

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame('stopped_logging', $patient->fresh()->adherence_status);
        $after = $this->overview();
        $this->assertSame([0, 1], [$after['stable'], $after['stopped_logging']]);
        $this->assertSame([$patient->id], $this->filtered('stopped_logging'));
        $this->assertSame([], $this->filtered('stable'));
        // The screen and the alert say the same thing.
        $this->assertTrue($patient->alerts()->where('type', Alert::TYPE_NO_LOG)->whereNull('resolved_at')->exists());
        $this->getJson("/api/v1/clients/{$patient->id}", $this->bearerFor($this->nutritionist))->assertJsonPath('adherence_status', 'stopped_logging');
    }

    public function test_a_patient_whose_rate_fell_reads_declining_without_logging_again(): void
    {
        $patient = $this->patient(['last_logged_at' => now()->subDays(2)]);
        $item = $this->planItem($patient);
        // Previous 7-day window: 4 of 4 on-plan (100%).
        foreach ([9, 10, 11, 12] as $day) {
            $this->log($patient, $day, $item);
        }
        // Current 7-day window: 2 of 4 on-plan (50%), a 50pp fall.
        foreach ([2, 3] as $day) {
            $this->log($patient, $day, $item);
        }
        foreach ([4, 5] as $day) {
            $this->log($patient, $day, null);
        }

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame('declining', $patient->fresh()->adherence_status);
        $this->assertSame(1, $this->overview()['declining']);
        $this->assertSame([$patient->id], $this->filtered('declining'));
    }

    public function test_archived_and_pending_patients_are_skipped(): void
    {
        $archived = $this->patient(['last_logged_at' => null, 'archived_at' => now()]);
        $pending = Subscriber::factory()->forNutritionist($this->nutritionist)->create(['adherence_status' => 'stable', 'last_logged_at' => null]);
        $active = $this->patient(['last_logged_at' => null]);

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame('stable', $archived->fresh()->adherence_status);
        $this->assertSame('stable', $pending->fresh()->adherence_status);
        $this->assertSame('stopped_logging', $active->fresh()->adherence_status);
        $this->assertSame(0, Alert::whereIn('subscriber_id', [$archived->id, $pending->id])->count());
    }

    public function test_running_twice_on_the_same_day_changes_nothing(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(3));
        $stale = $this->patient(['last_logged_at' => now()->subDays(5)]);
        $fresh = $this->patient(['last_logged_at' => now()->subDay()]);
        $this->log($fresh, 1, $this->planItem($fresh));

        $this->artisan('alerts:evaluate')->assertSuccessful();
        $snapshot = fn () => [
            DB::table('subscribers')->orderBy('id')->get(['id', 'adherence_status', 'updated_at'])->toArray(),
            DB::table('alerts')->orderBy('id')->get(['id', 'type', 'resolved_at', 'updated_at'])->toArray(),
        ];
        $first = $snapshot();

        $this->travel(2)->hours();
        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertEquals($first, $snapshot());
        $this->assertSame(0, app(SelfScheduler::class)->lastResult('alerts')['status_changes']);
        $this->assertSame(['stopped_logging', 'stable'], [$stale->fresh()->adherence_status, $fresh->fresh()->adherence_status]);
    }

    public function test_one_patients_failure_does_not_stop_the_rest(): void
    {
        $bad = $this->patient(['last_logged_at' => null]);
        $good = $this->patient(['last_logged_at' => null]);
        $real = app(AdherenceService::class);
        $this->mock(AdherenceService::class, fn ($mock) => $mock->shouldReceive('refreshStatus')->andReturnUsing(
            fn (Subscriber $s) => $s->id === $bad->id ? throw new \RuntimeException('boom') : $real->refreshStatus($s),
        ));

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame('stopped_logging', $good->fresh()->adherence_status);
        // The failing patient's alerts were still evaluated.
        $this->assertTrue($bad->alerts()->where('type', Alert::TYPE_NO_LOG)->exists());
        $this->assertSame(1, app(SelfScheduler::class)->lastResult('alerts')['failures']);
    }

    public function test_the_run_is_visible_through_scheduler_status_and_the_admin_overview(): void
    {
        $this->patient(['last_logged_at' => now()->subDays(4)]);
        // The scheduler diagnostics are admin-only.
        $auth = $this->bearerFor(tap(User::factory()->create())->assignRole('admin'));

        $this->getJson('/api/v1/system/scheduler-status', $auth)->assertOk()->assertJsonPath('jobs.alerts.last_result', null);

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->getJson('/api/v1/system/scheduler-status', $auth)
            ->assertOk()
            ->assertJsonPath('jobs.alerts.overdue', false)
            ->assertJsonPath('jobs.alerts.last_result.patients', 1)
            ->assertJsonPath('jobs.alerts.last_result.status_changes', 1)
            ->assertJsonPath('jobs.alerts.last_result.stopped_logging', 1);

        $this->getJson('/api/v1/admin/overview', $auth)
            ->assertOk()
            ->assertJsonPath('last_daily_run.patients', 1);
    }

    public function test_resuming_follow_up_brings_the_stored_status_up_to_date(): void
    {
        $patient = $this->patient(['last_logged_at' => now()->subDays(6)]);
        $this->postJson("/api/v1/clients/{$patient->id}/archive", [], $this->bearerFor($this->nutritionist))->assertOk();

        $this->postJson("/api/v1/clients/{$patient->id}/resume", [], $this->bearerFor($this->nutritionist))
            ->assertOk()
            ->assertJsonPath('client.adherence_status', 'stopped_logging');
    }
}
