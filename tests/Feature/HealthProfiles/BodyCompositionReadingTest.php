<?php

namespace Tests\Feature\HealthProfiles;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** FR-10: body-composition readings, preserved across visits. */
class BodyCompositionReadingTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_readings_accumulate_as_history_rather_than_overwrite(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => '2026-08-01', 'weight_kg' => 82,
        ], $this->bearerFor($nutritionist))->assertCreated();

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => '2026-09-01', 'weight_kg' => 80, 'body_fat_percent' => 23.5,
        ], $this->bearerFor($nutritionist))->assertCreated();

        $index = $this->getJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", $this->bearerFor($nutritionist));

        $index->assertOk();
        $this->assertCount(2, $index->json());
        // orderByDesc('recorded_at') on the relation — most recent first.
        $this->assertSame('2026-09-01', $index->json('0.recorded_at'));
        $this->assertSame(23.5, $index->json('0.body_fat_percent'));
    }

    public function test_a_nutritionist_cannot_read_or_write_another_nutritionists_client_readings(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $othersClient = Subscriber::factory()->create();

        $this->getJson("/api/v1/clients/{$othersClient->id}/body-composition-readings", $this->bearerFor($nutritionist))
            ->assertNotFound();

        $this->postJson("/api/v1/clients/{$othersClient->id}/body-composition-readings", [
            'recorded_at' => '2026-08-01', 'weight_kg' => 80,
        ], $this->bearerFor($nutritionist))->assertNotFound();
    }

    public function test_a_future_reading_date_is_rejected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->addDay()->toDateString(), 'weight_kg' => 80,
        ], $this->bearerFor($nutritionist))->assertUnprocessable();
    }

    /**
     * BR-19 limits what the PATIENT enters. The nutritionist onboarding a
     * patient types in clinic readings from paper records, however old.
     */
    public function test_the_nutritionist_can_enter_clinic_readings_of_any_past_date(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $token = $this->bearerFor($nutritionist);

        foreach ([now()->subDays(8), now()->subMonths(6), now()->subYears(3)] as $date) {
            $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
                'recorded_at' => $date->toDateString(), 'weight_kg' => 84, 'body_fat_percent' => 27,
            ], $token)->assertCreated()->assertJsonPath('source', 'clinic-analyser');
        }

        $this->assertSame(3, $subscriber->bodyCompositionReadings()->count());
        // ...while a date in the future is still refused.
        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->addDay()->toDateString(), 'weight_kg' => 84,
        ], $token)->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    }

    /** weight_kg is NOT NULL in the table, so it must be required, not fail in the database. */
    public function test_a_reading_without_weight_is_a_422_not_a_500(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->postJson("/api/v1/clients/{$subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->toDateString(), 'waist_cm' => 88,
        ], $this->bearerFor($nutritionist))->assertUnprocessable()->assertJsonValidationErrors('weight_kg');

        $this->assertSame(0, $subscriber->bodyCompositionReadings()->count());
    }
}
