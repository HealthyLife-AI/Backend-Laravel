<?php

namespace Tests\Feature\Phase1;

use App\Models\Alert;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1, C1: alerts.type was an ENUM of three values. The guarded migration
 * turns it into a string so the new types can be stored on MySQL and on the
 * SQLite test database alike, without touching existing rows.
 */
class AlertTypeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_alert_types_and_severity_can_be_stored(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => User::factory()->nutritionist()->create()->id]);

        foreach (['no_log', 'calories_exceeded', 'milestone', 'measurement_overdue', 'quick_request'] as $type) {
            DB::table('alerts')->insert(['subscriber_id' => $subscriber->id, 'type' => $type, 'message' => 'm', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame(5, Alert::count());
        $this->assertSame(['normal'], Alert::pluck('severity')->unique()->values()->all(), 'severity defaults to normal');
    }
}
