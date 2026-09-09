<?php

namespace Tests\Feature\Foods;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S3-05 / FR-24, BR-5: nutritionist submission enters review; only an admin approves/rejects. */
class FoodSubmissionTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_submission_is_pending_and_not_searchable_until_approved(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $submit = $this->postJson('/api/v1/foods', [
            'name_en' => 'Kabsa',
            'calories_per_100g' => 180,
            'protein_g_per_100g' => 8,
            'carbs_g_per_100g' => 22,
            'fat_g_per_100g' => 6,
        ], $this->bearerFor($nutritionist));

        $submit->assertCreated()->assertJsonPath('status', 'pending');
        $foodId = $submit->json('id');

        $this->getJson('/api/v1/foods/search?q=Kabsa', $this->bearerFor($nutritionist))
            ->assertJsonCount(0, 'data');

        $admin = User::factory()->admin()->create();
        $this->postJson("/api/v1/foods/{$foodId}/approve", [], $this->bearerFor($admin))
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->getJson('/api/v1/foods/search?q=Kabsa', $this->bearerFor($nutritionist))
            ->assertJsonCount(1, 'data');
    }

    public function test_a_nutritionist_cannot_approve_their_own_submission(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $submit = $this->postJson('/api/v1/foods', [
            'name_en' => 'Test Dish',
            'calories_per_100g' => 100,
            'protein_g_per_100g' => 5,
            'carbs_g_per_100g' => 10,
            'fat_g_per_100g' => 2,
        ], $this->bearerFor($nutritionist));

        $this->postJson("/api/v1/foods/{$submit->json('id')}/approve", [], $this->bearerFor($nutritionist))
            ->assertForbidden();
    }

    public function test_admin_can_see_the_pending_queue(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $admin = User::factory()->admin()->create();

        $this->postJson('/api/v1/foods', [
            'name_en' => 'Queued Dish',
            'calories_per_100g' => 100,
            'protein_g_per_100g' => 5,
            'carbs_g_per_100g' => 10,
            'fat_g_per_100g' => 2,
        ], $this->bearerFor($nutritionist));

        $this->getJson('/api/v1/foods/pending', $this->bearerFor($admin))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
