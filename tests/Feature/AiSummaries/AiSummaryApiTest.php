<?php

namespace Tests\Feature\AiSummaries;

use App\Models\AiSummary;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S5-04 / FR-21, ai_summary.view: reading a client's weekly summaries. */
class AiSummaryApiTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_reads_their_clients_summaries(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $summary = AiSummary::create([
            'subscriber_id' => $subscriber->id,
            'week_start' => now()->subWeek()->startOfWeek()->toDateString(),
            'summary_text' => 'Good week overall.',
            'is_fallback' => false,
            'generated_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/ai-summaries",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($summary->id, $response->json('data.0.id'));
        $this->assertFalse($response->json('data.0.is_fallback'));
    }

    public function test_a_nutritionist_cannot_read_another_nutritionists_client_summaries(): void
    {
        $theirs = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $theirs->id]);
        AiSummary::create([
            'subscriber_id' => $subscriber->id, 'week_start' => now()->toDateString(),
            'summary_text' => 'x', 'generated_at' => now(),
        ]);

        $mine = User::factory()->nutritionist()->create();

        $this->getJson(
            "/api/v1/clients/{$subscriber->id}/ai-summaries",
            $this->bearerFor($mine)
        )->assertNotFound();
    }

    public function test_a_client_cannot_read_ai_summaries(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->active()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        $this->getJson(
            "/api/v1/clients/{$subscriber->id}/ai-summaries",
            $this->bearerFor($client)
        )->assertForbidden();
    }
}
