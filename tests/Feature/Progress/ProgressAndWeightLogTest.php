<?php

namespace Tests\Feature\Progress;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S4-02 (client weight logging), S4-04 (progress data) and S4-05
 * (retry-safe logging for the offline mobile queue).
 */
class ProgressAndWeightLogTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: Subscriber, 2: User} */
    private function makeClient(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        return [$client, $subscriber, $nutritionist];
    }

    public function test_a_client_logs_their_own_weight(): void
    {
        [$client, $subscriber] = $this->makeClient();

        $response = $this->postJson('/api/v1/me/weight-logs', [
            'weight_kg' => 82.5,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertSame(82.5, $response->json('weight_kg'));
        $this->assertDatabaseHas('body_composition_readings', [
            'subscriber_id' => $subscriber->id,
            'weight_kg' => 82.5,
        ]);
    }

    /**
     * S4-05 for weight: the table holds one reading per date, so a
     * replayed offline entry updates that day's row. The date IS the
     * idempotency key here — no separate key needed.
     */
    public function test_relogging_the_same_day_updates_rather_than_duplicates(): void
    {
        [$client, $subscriber] = $this->makeClient();
        $header = $this->bearerFor($client);

        $this->postJson('/api/v1/me/weight-logs', ['weight_kg' => 82.5], $header)->assertCreated();
        $this->postJson('/api/v1/me/weight-logs', ['weight_kg' => 82.1], $header)->assertOk();

        $this->assertSame(1, $subscriber->bodyCompositionReadings()->count());
        $this->assertSame('82.10', $subscriber->bodyCompositionReadings()->first()->weight_kg);
    }

    /**
     * A client weighs themselves on a bathroom scale; body fat and muscle
     * mass come from a clinic analyser (FR-10). Accepting them here would
     * let a self-reported guess sit in the same column as a measurement.
     */
    public function test_a_client_cannot_submit_clinic_only_measurements(): void
    {
        [$client, $subscriber] = $this->makeClient();

        $this->postJson('/api/v1/me/weight-logs', [
            'weight_kg' => 80,
            'body_fat_percent' => 12,
            'muscle_mass_kg' => 40,
        ], $this->bearerFor($client))->assertCreated();

        $reading = $subscriber->bodyCompositionReadings()->first();
        $this->assertNull($reading->body_fat_percent);
        $this->assertNull($reading->muscle_mass_kg);
    }

    public function test_a_future_weight_date_is_rejected(): void
    {
        [$client] = $this->makeClient();

        $this->postJson('/api/v1/me/weight-logs', [
            'weight_kg' => 80,
            'recorded_at' => now()->addDay()->toDateString(),
        ], $this->bearerFor($client))
            ->assertStatus(422)
            ->assertJsonValidationErrors('recorded_at');
    }

    public function test_replaying_a_queued_meal_log_does_not_create_a_second_log(): void
    {
        [$client, $subscriber] = $this->makeClient();
        $header = $this->bearerFor($client);
        $payload = [
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 150,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $first = $this->postJson('/api/v1/me/meal-logs', $payload, $header);
        $retry = $this->postJson('/api/v1/me/meal-logs', $payload, $header);

        $first->assertCreated();
        // 200, not 201 and not an error: the mobile queue treats this as
        // success and stops retrying. An error would keep it queued.
        $retry->assertOk();
        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertSame(1, $subscriber->mealLogs()->count());
    }

    /** A second helping is a real second meal, not a retry — different key, two logs. */
    public function test_two_genuinely_separate_meals_are_both_recorded(): void
    {
        [$client, $subscriber] = $this->makeClient();
        $header = $this->bearerFor($client);
        $food = Food::factory()->create();

        foreach ([Str::uuid(), Str::uuid()] as $key) {
            $this->postJson('/api/v1/me/meal-logs', [
                'food_id' => $food->id,
                'quantity_grams' => 150,
                'idempotency_key' => (string) $key,
            ], $header)->assertCreated();
        }

        $this->assertSame(2, $subscriber->mealLogs()->count());
    }

    public function test_progress_returns_the_weight_trend_in_date_order(): void
    {
        [$client, $subscriber, $nutritionist] = $this->makeClient();
        $header = $this->bearerFor($client);

        foreach ([[3, 84.0], [2, 83.0], [1, 82.0]] as [$daysAgo, $weight]) {
            $this->postJson('/api/v1/me/weight-logs', [
                'weight_kg' => $weight,
                'recorded_at' => now()->subDays($daysAgo)->toDateString(),
            ], $header)->assertCreated();
        }

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/progress",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertEquals([84, 83, 82], array_column($response->json('weight_trend'), 'weight_kg'));
        $this->assertEquals(-2, $response->json('body_composition.change.weight_kg'));
    }

    /** One reading is a position, not a trend — reporting 0 would imply the client held steady. */
    public function test_change_is_null_with_fewer_than_two_readings(): void
    {
        [$client, $subscriber, $nutritionist] = $this->makeClient();

        $this->postJson('/api/v1/me/weight-logs', ['weight_kg' => 80], $this->bearerFor($client))
            ->assertCreated();

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/progress",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertNull($response->json('body_composition.change'));
        $this->assertNotNull($response->json('body_composition.latest'));
    }

    public function test_a_nutritionist_cannot_read_another_nutritionists_client_progress(): void
    {
        [, $subscriber] = $this->makeClient();
        $outsider = User::factory()->nutritionist()->create();

        $this->getJson(
            "/api/v1/clients/{$subscriber->id}/progress",
            $this->bearerFor($outsider)
        )->assertNotFound();
    }
}
