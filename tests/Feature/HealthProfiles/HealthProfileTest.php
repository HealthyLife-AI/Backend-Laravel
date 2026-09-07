<?php

namespace Tests\Feature\HealthProfiles;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** FR-07/FR-09/FR-11: the health-profile form. */
class HealthProfileTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'weight_kg' => 80,
            'height_cm' => 180,
            'age' => 30,
            'gender' => 'male',
            'activity_level' => 'sedentary',
            'health_conditions' => ['مقاومة أنسولين'],
            'medications' => [['name' => 'Levothyroxine', 'dose' => '50mcg', 'schedule' => 'morning, fasting']],
            'allergies' => ['Peanuts'],
            'food_preferences' => ['Vegetarian breakfast'],
        ], $overrides);
    }

    public function test_creating_a_profile_computes_daily_calorie_needs(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $response = $this->putJson(
            "/api/v1/clients/{$subscriber->id}/health-profile",
            $this->payload(),
            $this->bearerFor($nutritionist)
        );

        // 201: JsonResource auto-reports Created when the wrapped model
        // `wasRecentlyCreated` — this is the first save for this client.
        $response->assertCreated();
        // BMR = 10*80 + 6.25*180 - 5*30 + 5 = 1780; TDEE sedentary (x1.2) = 2136.
        $this->assertSame(2136, $response->json('daily_calorie_needs'));
        $this->assertSame(['مقاومة أنسولين'], $response->json('health_conditions'));
        $this->assertSame('Levothyroxine', $response->json('medications.0.name'));
    }

    public function test_updating_a_profile_recalculates_daily_calorie_needs(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->putJson("/api/v1/clients/{$subscriber->id}/health-profile", $this->payload(), $this->bearerFor($nutritionist));

        $updated = $this->putJson(
            "/api/v1/clients/{$subscriber->id}/health-profile",
            $this->payload(['weight_kg' => 90]),
            $this->bearerFor($nutritionist)
        );

        // BMR = 10*90 + 6.25*180 - 5*30 + 5 = 1880; TDEE = 1880 * 1.2 = 2256.
        $this->assertSame(2256, $updated->json('daily_calorie_needs'));
        $this->assertDatabaseCount('health_profiles', 1); // upsert, not a second row
    }

    public function test_show_returns_no_content_before_a_profile_exists(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->getJson("/api/v1/clients/{$subscriber->id}/health-profile", $this->bearerFor($nutritionist))
            ->assertNoContent();
    }

    public function test_a_nutritionist_cannot_write_another_nutritionists_client_profile(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $othersClient = Subscriber::factory()->create();

        $this->putJson(
            "/api/v1/clients/{$othersClient->id}/health-profile",
            $this->payload(),
            $this->bearerFor($nutritionistA)
        )->assertNotFound();
    }

    public function test_invalid_gender_is_rejected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->putJson(
            "/api/v1/clients/{$subscriber->id}/health-profile",
            $this->payload(['gender' => 'other']),
            $this->bearerFor($nutritionist)
        )->assertUnprocessable()->assertJsonValidationErrors('gender');
    }
}
