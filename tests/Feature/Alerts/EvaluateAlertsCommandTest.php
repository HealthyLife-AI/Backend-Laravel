<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5-01: the scheduled command itself — iteration scope and per-client
 * failure isolation, as distinct from the rule logic (AlertEvaluationTest).
 */
class EvaluateAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_evaluates_every_active_subscriber(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $active = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $pending = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]); // not active

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $active->id, 'type' => Alert::TYPE_NO_LOG]);
        $this->assertDatabaseMissing('alerts', ['subscriber_id' => $pending->id]);
    }

    /** Runs across every nutritionist — no auth context, so NutritionistScope does not narrow it (by design, not by accident). */
    public function test_it_evaluates_active_clients_across_every_nutritionist(): void
    {
        $first = Subscriber::factory()->active()->create(['nutritionist_id' => User::factory()->nutritionist()->create()->id]);
        $second = Subscriber::factory()->active()->create(['nutritionist_id' => User::factory()->nutritionist()->create()->id]);

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['subscriber_id' => $first->id]);
        $this->assertDatabaseHas('alerts', ['subscriber_id' => $second->id]);
    }

    public function test_it_returns_success_when_there_are_no_active_subscribers(): void
    {
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertDatabaseCount('alerts', 0);
    }
}
