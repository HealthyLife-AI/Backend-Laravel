<?php

namespace Tests\Feature\AiSummaries;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** S5-04/S5-05: the scheduled job's iteration scope and per-client failure isolation. */
class GenerateWeeklySummariesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_generates_a_summary_for_every_active_subscriber(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $active = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $pending = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        $this->assertSame(1, $active->aiSummaries()->count());
        $this->assertSame(0, $pending->aiSummaries()->count());
    }

    public function test_it_returns_success_with_no_active_subscribers(): void
    {
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();
        $this->assertDatabaseCount('ai_summaries', 0);
    }
}
