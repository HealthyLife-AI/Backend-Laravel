<?php

namespace Tests\Feature\HealthProfiles;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The plan designer's calorie-floor warning reads its numbers from the
 * health-profile response, which reads them from config/nutrition.php —
 * the one place they live. Changing the config (or its env override)
 * must change what the dashboard is told.
 */
class CalorieFloorTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->subscriber = Subscriber::factory()->create(['nutritionist_id' => $this->nutritionist->id]);

        $this->putJson("/api/v1/clients/{$this->subscriber->id}/health-profile", [
            'weight_kg' => 70, 'height_cm' => 165, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary',
        ], $this->bearerFor($this->nutritionist))->assertSuccessful();
    }

    private function profile(): TestResponse
    {
        return $this->getJson("/api/v1/clients/{$this->subscriber->id}/health-profile", $this->bearerFor($this->nutritionist))->assertOk();
    }

    public function test_the_default_floor_is_served(): void
    {
        $this->profile()
            ->assertJsonPath('calorie_floor.female', 1200)
            ->assertJsonPath('calorie_floor.male', 1500);
    }

    public function test_changing_the_config_changes_the_api_value(): void
    {
        config(['nutrition.calorie_floor.female' => 1300, 'nutrition.calorie_floor.male' => 1600]);

        $this->profile()
            ->assertJsonPath('calorie_floor.female', 1300)
            ->assertJsonPath('calorie_floor.male', 1600);
    }

    /** The save response carries it too, so the form can't go stale after saving. */
    public function test_the_save_response_carries_the_floor(): void
    {
        config(['nutrition.calorie_floor.female' => 1250]);

        $this->putJson("/api/v1/clients/{$this->subscriber->id}/health-profile", [
            'weight_kg' => 71, 'height_cm' => 165, 'age' => 30,
            'gender' => 'female', 'activity_level' => 'sedentary',
        ], $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('calorie_floor.female', 1250);
    }
}
