<?php

namespace Tests\Feature\Alerts;

use App\Models\Alert;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S5-02 / FR-20, alerts.view: listing and marking alerts read. */
class AlertApiTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_lists_alerts_for_their_own_roster(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $alert = Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'No log for 3+ days.']);

        $response = $this->getJson('/api/v1/alerts', $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($alert->id, $response->json('data.0.id'));
    }

    /** The isolation case: whereHas('subscriber') must honour NutritionistScope. */
    public function test_a_nutritionist_never_sees_another_nutritionists_alerts(): void
    {
        $mine = User::factory()->nutritionist()->create();
        $theirs = User::factory()->nutritionist()->create();
        $theirSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $theirs->id]);
        Alert::create(['subscriber_id' => $theirSubscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'x']);

        $response = $this->getJson('/api/v1/alerts', $this->bearerFor($mine));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_client_cannot_list_alerts(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');

        $this->getJson('/api/v1/alerts', $this->bearerFor($client))->assertForbidden();
    }

    public function test_filtering_by_unread_only(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $unread = Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'a']);
        Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE, 'message' => 'b', 'is_read' => true]);

        $response = $this->getJson('/api/v1/alerts?is_read=0', $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($unread->id, $response->json('data.0.id'));
    }

    /** Omitting the filter entirely must return everything, not silently default to unread-only. */
    public function test_omitting_the_filter_returns_both_read_and_unread(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'a']);
        Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE, 'message' => 'b', 'is_read' => true]);

        $response = $this->getJson('/api/v1/alerts', $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_marking_an_alert_read(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $alert = Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'a']);

        $response = $this->patchJson("/api/v1/alerts/{$alert->id}/read", [], $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertTrue($response->json('is_read'));
        $this->assertDatabaseHas('alerts', ['id' => $alert->id, 'is_read' => true]);
    }

    public function test_a_nutritionist_cannot_mark_another_nutritionists_alert_read(): void
    {
        $mine = User::factory()->nutritionist()->create();
        $theirs = User::factory()->nutritionist()->create();
        $theirSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $theirs->id]);
        $theirAlert = Alert::create(['subscriber_id' => $theirSubscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'x']);

        $this->patchJson("/api/v1/alerts/{$theirAlert->id}/read", [], $this->bearerFor($mine))
            ->assertNotFound();

        $this->assertDatabaseHas('alerts', ['id' => $theirAlert->id, 'is_read' => false]);
    }

    /** A milestone alert is never resolved — is_resolved must read false, not error, when resolved_at is null by design. */
    public function test_a_milestone_alert_reports_unresolved(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_MILESTONE, 'message' => 'x']);

        $response = $this->getJson('/api/v1/alerts', $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertFalse($response->json('data.0.is_resolved'));
    }
}
