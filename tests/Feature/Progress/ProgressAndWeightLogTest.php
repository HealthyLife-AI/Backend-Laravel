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

        $response = $this->postJson('/api/v1/me/measurements', [
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

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 82.5], $header)->assertCreated();
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 82.1], $header)->assertOk();

        $this->assertSame(1, $subscriber->bodyCompositionReadings()->count());
        $this->assertSame('82.10', $subscriber->bodyCompositionReadings()->first()->weight_kg);
    }

    /**
     * BR-11, first half: a tape measure is all a remote client needs for
     * waist, hip, thigh and arm, so those ARE accepted from the client.
     */
    public function test_a_client_can_submit_their_own_circumferences(): void
    {
        [$client, $subscriber] = $this->makeClient();

        $response = $this->postJson('/api/v1/me/measurements', [
            'weight_kg' => 80,
            'waist_cm' => 92.5,
            'hip_cm' => 101,
            'thigh_cm' => 58,
            'arm_cm' => 31.5,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $reading = $subscriber->bodyCompositionReadings()->first();
        $this->assertEquals(92.5, $reading->waist_cm);
        $this->assertEquals(101, $reading->hip_cm);
        $this->assertEquals(58, $reading->thigh_cm);
        $this->assertEquals(31.5, $reading->arm_cm);
    }

    /**
     * BR-11, second half — the regression guard. Body fat, muscle mass and
     * water percentage come off a bio-impedance analyser, so a client's
     * guess must never reach those columns even if the request carries it.
     */
    public function test_a_client_still_cannot_submit_analyser_only_figures(): void
    {
        [$client, $subscriber] = $this->makeClient();

        $this->postJson('/api/v1/me/measurements', [
            'weight_kg' => 80,
            'body_fat_percent' => 12,
            'muscle_mass_kg' => 40,
            'water_percent' => 55,
        ], $this->bearerFor($client))->assertCreated();

        $reading = $subscriber->bodyCompositionReadings()->first();
        $this->assertNull($reading->body_fat_percent);
        $this->assertNull($reading->muscle_mass_kg);
        $this->assertNull($reading->water_percent);
    }

    /** BR-13: a client's own entry is stamped self-reported, not analyser-grade. */
    public function test_a_client_entry_is_stamped_self_reported(): void
    {
        [$client, $subscriber] = $this->makeClient();

        $response = $this->postJson('/api/v1/me/measurements', [
            'weight_kg' => 80,
        ], $this->bearerFor($client));

        $response->assertCreated();
        $this->assertSame('self-reported', $response->json('source'));
        $this->assertSame('self-reported', $subscriber->bodyCompositionReadings()->first()->source);
    }

    /** The nutritionist's own endpoint is the clinic visit — analyser-grade. */
    public function test_a_nutritionist_entry_is_stamped_clinic_analyser(): void
    {
        [, $subscriber, $nutritionist] = $this->makeClient();

        $response = $this->postJson(
            "/api/v1/clients/{$subscriber->id}/body-composition-readings",
            ['recorded_at' => now()->toDateString(), 'weight_kg' => 80, 'body_fat_percent' => 22],
            $this->bearerFor($nutritionist)
        );

        $response->assertCreated();
        $this->assertSame('clinic-analyser', $response->json('source'));
    }

    /**
     * A client correcting a day the nutritionist already measured in
     * clinic must not downgrade that row to self-reported — the analyser
     * figures on it are still analyser figures.
     */
    public function test_a_client_edit_does_not_downgrade_a_clinic_reading(): void
    {
        [$client, $subscriber, $nutritionist] = $this->makeClient();
        $today = now()->toDateString();

        $this->postJson(
            "/api/v1/clients/{$subscriber->id}/body-composition-readings",
            ['recorded_at' => $today, 'weight_kg' => 80, 'body_fat_percent' => 22],
            $this->bearerFor($nutritionist)
        )->assertCreated();

        $this->postJson('/api/v1/me/measurements', [
            'weight_kg' => 79.4,
            'recorded_at' => $today,
        ], $this->bearerFor($client))->assertOk();

        $reading = $subscriber->bodyCompositionReadings()->first();
        $this->assertSame('clinic-analyser', $reading->source);
        $this->assertEquals(79.4, $reading->weight_kg);
        $this->assertEquals(22, $reading->body_fat_percent);
    }

    /** An empty body would otherwise create a reading holding nothing. */
    public function test_a_measurement_with_no_values_is_rejected(): void
    {
        [$client] = $this->makeClient();

        $this->postJson('/api/v1/me/measurements', [], $this->bearerFor($client))
            ->assertStatus(422)
            ->assertJsonValidationErrors('weight_kg');
    }

    public function test_a_future_weight_date_is_rejected(): void
    {
        [$client] = $this->makeClient();

        $this->postJson('/api/v1/me/measurements', [
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
            $this->postJson('/api/v1/me/measurements', [
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

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80], $this->bearerFor($client))
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
