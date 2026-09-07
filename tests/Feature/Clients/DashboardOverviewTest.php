<?php

namespace Tests\Feature\Clients;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** F-2: dashboard overview stat counts, one nutritionist at a time. */
class DashboardOverviewTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_overview_counts_are_accurate_and_scoped_to_the_caller(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $otherNutritionist = User::factory()->nutritionist()->create();

        Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'adherence_status' => 'on_track']);
        Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'adherence_status' => 'needs_attention']);
        Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]); // pending, no adherence yet

        // Belongs to someone else — must not affect the counts above.
        Subscriber::factory()->active()->create(['nutritionist_id' => $otherNutritionist->id, 'adherence_status' => 'on_track']);

        $response = $this->getJson('/api/v1/dashboard/overview', $this->bearerFor($nutritionist));

        $response->assertOk()->assertJson([
            'total' => 3,
            'active' => 2,
            'pending' => 1,
            'on_track' => 1,
            'needs_attention' => 1,
            'late' => 0,
        ]);

        // No logging feature yet (later sprint) — every active client is
        // honestly reported as not-logged-today, never fabricated.
        $this->assertSame(2, $response->json('not_logged_today'));
    }

    public function test_a_nutritionist_with_no_clients_gets_all_zero_counts(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $this->getJson('/api/v1/dashboard/overview', $this->bearerFor($nutritionist))
            ->assertOk()
            ->assertJson([
                'total' => 0, 'active' => 0, 'pending' => 0,
                'on_track' => 0, 'needs_attention' => 0, 'late' => 0, 'not_logged_today' => 0,
            ]);
    }
}
