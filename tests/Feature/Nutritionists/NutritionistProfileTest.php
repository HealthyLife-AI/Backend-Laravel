<?php

namespace Tests\Feature\Nutritionists;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S4-00 / PRD §5.2: the nutritionist's own professional details — the
 * table the SRS documented since early design but which a Sprint 4 audit
 * found had never been built.
 */
class NutritionistProfileTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** No row is created at registration, so the first read has to make one. */
    public function test_the_profile_is_created_on_first_read(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $this->assertDatabaseCount('nutritionist_profiles', 0);

        $response = $this->getJson('/api/v1/me/nutritionist-profile', $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertSame('basic', $response->json('plan_tier'));
        $this->assertDatabaseHas('nutritionist_profiles', ['user_id' => $nutritionist->id]);
    }

    public function test_a_nutritionist_updates_their_own_details(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $response = $this->putJson('/api/v1/me/nutritionist-profile', [
            'specialty' => 'Clinical nutrition',
            'clinic_name' => 'Gaza Nutrition Center',
            'bio' => 'Ten years of practice.',
        ], $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertSame('Clinical nutrition', $response->json('specialty'));
        $this->assertDatabaseHas('nutritionist_profiles', [
            'user_id' => $nutritionist->id,
            'clinic_name' => 'Gaza Nutrition Center',
        ]);
    }

    /**
     * The one that matters: `plan_tier` is billing state, not profile
     * content. Accepting it here would let a nutritionist move themselves
     * onto a paid tier for free by adding one field to the request body.
     */
    public function test_a_nutritionist_cannot_promote_themselves_to_a_paid_tier(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $response = $this->putJson('/api/v1/me/nutritionist-profile', [
            'specialty' => 'Sports nutrition',
            'plan_tier' => 'professional',
        ], $this->bearerFor($nutritionist));

        $response->assertOk();
        $this->assertSame('basic', $response->json('plan_tier'));
        $this->assertDatabaseHas('nutritionist_profiles', [
            'user_id' => $nutritionist->id,
            'plan_tier' => 'basic',
        ]);
    }

    public function test_each_nutritionist_reads_only_their_own_profile(): void
    {
        $first = User::factory()->nutritionist()->create();
        $second = User::factory()->nutritionist()->create();

        $this->putJson('/api/v1/me/nutritionist-profile', [
            'specialty' => 'Paediatric nutrition',
        ], $this->bearerFor($first))->assertOk();

        $response = $this->getJson('/api/v1/me/nutritionist-profile', $this->bearerFor($second));

        $response->assertOk();
        $this->assertNull($response->json('specialty'));
    }

    public function test_a_client_cannot_reach_the_nutritionist_profile_endpoint(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        $this->getJson('/api/v1/me/nutritionist-profile', $this->bearerFor($client))
            ->assertForbidden();
    }

    public function test_an_overlong_bio_is_rejected(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $this->putJson('/api/v1/me/nutritionist-profile', [
            'bio' => str_repeat('a', 1001),
        ], $this->bearerFor($nutritionist))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bio');
    }

    /** Repeated reads must not accumulate rows — user_id is unique. */
    public function test_reading_twice_does_not_create_a_second_profile(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $header = $this->bearerFor($nutritionist);

        $this->getJson('/api/v1/me/nutritionist-profile', $header)->assertOk();
        $this->getJson('/api/v1/me/nutritionist-profile', $header)->assertOk();

        $this->assertDatabaseCount('nutritionist_profiles', 1);
    }
}
