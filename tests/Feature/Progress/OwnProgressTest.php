<?php

namespace Tests\Feature\Progress;

use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The patient reading their own adherence and progress. Before
 * /me/adherence and /me/progress existed, the only routes were
 * `clients/{subscriber}/…`, which answered 404 to every client token.
 */
class OwnProgressTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        [$this->client, $this->subscriber] = $this->makePatient();

        $food = Food::factory()->create();
        foreach ([9, 6, 3, 1] as $daysAgo) {
            MealLog::create(['subscriber_id' => $this->subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 150, 'meal_type' => 'lunch', 'logged_at' => now()->subDays($daysAgo)]);
        }
        $this->subscriber->forceFill(['last_logged_at' => now()->subDay()])->save();
        $this->subscriber->bodyCompositionReadings()->create(['recorded_at' => now()->subDays(20)->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => 84, 'body_fat_percent' => 26]);
        $this->subscriber->bodyCompositionReadings()->create(['recorded_at' => now()->subDays(2)->toDateString(), 'source' => 'self-reported', 'weight_kg' => 82.5, 'waist_cm' => 91]);
    }

    /** @return array{0: User, 1: Subscriber} */
    private function makePatient(): array
    {
        $client = User::factory()->create(['nutritionist_id' => $this->nutritionist->id]);
        $client->assignRole('client');

        return [$client, Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id])];
    }

    public function test_the_patient_reads_the_same_progress_their_nutritionist_sees(): void
    {
        $mine = $this->getJson('/api/v1/me/progress', $this->bearerFor($this->client))->assertOk();
        $theirs = $this->getJson("/api/v1/clients/{$this->subscriber->id}/progress", $this->bearerFor($this->nutritionist))->assertOk();

        $this->assertSame($theirs->json(), $mine->json());
        $this->assertCount(2, $mine->json('weight_trend'));
        $this->assertEquals(-1.5, $mine->json('body_composition.change.weight_kg'));
    }

    public function test_the_patient_reads_the_same_adherence_their_nutritionist_sees(): void
    {
        $mine = $this->getJson('/api/v1/me/adherence', $this->bearerFor($this->client))->assertOk();
        $theirs = $this->getJson("/api/v1/clients/{$this->subscriber->id}/adherence", $this->bearerFor($this->nutritionist))->assertOk();

        $this->assertSame($theirs->json(), $mine->json());
        $this->assertArrayHasKey('status', $mine->json());
    }

    public function test_the_date_window_applies_to_both(): void
    {
        $query = '?from='.now()->subDays(5)->toDateString().'&to='.now()->toDateString();

        $progress = $this->getJson("/api/v1/me/progress{$query}", $this->bearerFor($this->client))->assertOk();
        $this->assertCount(1, $progress->json('weight_trend'));
        $this->assertNull($progress->json('body_composition.change'), 'one reading in the window is a position, not a trend');

        $this->getJson("/api/v1/me/adherence{$query}", $this->bearerFor($this->client))->assertOk();
        $this->getJson('/api/v1/me/progress?from='.now()->toDateString(), $this->bearerFor($this->client))->assertUnprocessable();
    }

    public function test_it_only_ever_shows_the_callers_own_data(): void
    {
        [$other, $otherSubscriber] = $this->makePatient();
        $otherSubscriber->bodyCompositionReadings()->create(['recorded_at' => now()->toDateString(), 'source' => 'self-reported', 'weight_kg' => 55]);

        $mine = $this->getJson('/api/v1/me/progress', $this->bearerFor($this->client))->assertOk()->json();
        $theirs = $this->getJson('/api/v1/me/progress', $this->bearerFor($other))->assertOk()->json();

        $this->assertNotContains(55.0, array_map('floatval', array_column($mine['weight_trend'], 'weight_kg')));
        $this->assertSame([55.0], array_map('floatval', array_column($theirs['weight_trend'], 'weight_kg')));
        // No id in the URL to point at anyone else.
        $this->getJson("/api/v1/me/progress?subscriber={$otherSubscriber->id}", $this->bearerFor($this->client))
            ->assertOk()->assertJson($mine);
    }

    public function test_a_nutritionist_cannot_use_the_patient_endpoints(): void
    {
        $this->getJson('/api/v1/me/progress', $this->bearerFor($this->nutritionist))->assertForbidden();
        $this->getJson('/api/v1/me/adherence', $this->bearerFor($this->nutritionist))->assertForbidden();
    }

    public function test_an_archived_patient_gets_follow_up_ended(): void
    {
        $token = $this->bearerFor($this->client);
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->getJson('/api/v1/me/progress', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->getJson('/api/v1/me/adherence', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
    }

    public function test_the_nutritionist_routes_stay_nutritionist_only(): void
    {
        // A patient can't reach them (nor their own row through them); the
        // patient app uses /me/progress and /me/adherence instead.
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/progress", $this->bearerFor($this->client))->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/adherence", $this->bearerFor($this->client))->assertNotFound();
    }

    public function test_the_unauthenticated_are_refused(): void
    {
        $this->getJson('/api/v1/me/progress')->assertUnauthorized();
        $this->getJson('/api/v1/me/adherence')->assertUnauthorized();
    }
}
