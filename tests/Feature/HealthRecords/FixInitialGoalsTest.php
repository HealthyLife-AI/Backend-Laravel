<?php

namespace Tests\Feature\HealthRecords;

use App\Models\PatientGoal;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** B12: recovering the goal chosen at creation, saved as 'other' by the old bug. */
class FixInitialGoalsTest extends TestCase
{
    use RefreshDatabase;

    private function patient(string $legacy, string $goalType, string $created, ?string $updated = null): int
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $subscriber = Subscriber::factory()->active()->forNutritionist(User::factory()->nutritionist()->create())->create(['goal' => $legacy]);
        DB::table('patient_goals')->where('subscriber_id', $subscriber->id)->delete();
        DB::table('patient_goals')->insert(['subscriber_id' => $subscriber->id, 'goal_type' => $goalType, 'created_at' => $created, 'updated_at' => $updated ?? $created]);

        return $subscriber->id;
    }

    public function test_dry_run_lists_and_apply_fixes_only_untouched_rows_before_the_cutoff(): void
    {
        $buggy = $this->patient('weight_loss', 'other', '2026-10-07 10:00:00');
        $monitoring = $this->patient('health_monitoring', 'other', '2026-10-07 11:00:00');
        $edited = $this->patient('weight_gain', 'other', '2026-10-07 12:00:00', '2026-10-08 09:00:00');
        $afterDeploy = $this->patient('health_monitoring', 'other', '2026-10-09 12:00:00');
        $fine = $this->patient('weight_loss', 'weight_loss', '2026-10-07 10:00:00');

        $this->artisan('health-records:fix-initial-goals', ['--before' => '2026-10-09 10:00'])
            ->expectsOutputToContain('Dry run — would fix: 2')
            ->expectsOutputToContain('check by hand: 1')
            ->assertSuccessful();
        $this->assertSame('other', PatientGoal::where('subscriber_id', $buggy)->value('goal_type'));

        $this->artisan('health-records:fix-initial-goals', ['--before' => '2026-10-09 10:00', '--apply' => true])->assertSuccessful();

        $this->assertSame('weight_loss', PatientGoal::where('subscriber_id', $buggy)->value('goal_type'));
        $this->assertSame('health_energy', PatientGoal::where('subscriber_id', $monitoring)->value('goal_type'));
        $this->assertSame('other', PatientGoal::where('subscriber_id', $edited)->value('goal_type'));
        $this->assertSame('other', PatientGoal::where('subscriber_id', $afterDeploy)->value('goal_type'));
        $this->assertSame('weight_loss', PatientGoal::where('subscriber_id', $fine)->value('goal_type'));
    }
}
