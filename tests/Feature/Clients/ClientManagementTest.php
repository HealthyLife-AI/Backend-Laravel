<?php

namespace Tests\Feature\Clients;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * FR-02/FR-06 + the sprint's non-negotiable security check (S2-18 /
 * NFR-12): a nutritionist manages only their own clients.
 */
class ClientManagementTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_can_add_a_client_and_receives_an_invite_token(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $response = $this->postJson('/api/v1/clients', [
            'name' => 'Sara Ahmad',
            'phone' => '0501234567',
            'goal' => 'weight_loss',
        ], $this->bearerFor($nutritionist));

        $response->assertCreated()->assertJsonStructure([
            'client' => ['id', 'code', 'name', 'phone', 'goal', 'status'],
            'invite_token',
            'invite_expires_at',
        ]);

        $this->assertSame('Sara Ahmad', $response->json('client.name'));
        $this->assertSame('pending', $response->json('client.status'));
        $this->assertStringStartsWith('PT-', $response->json('client.code'));

        $subscriber = Subscriber::query()->where('nutritionist_id', $nutritionist->id)->firstOrFail();
        $this->assertTrue($subscriber->user->hasRole('client'));
        $this->assertSame($nutritionist->id, $subscriber->nutritionist_id);

        $this->assertDatabaseCount('client_invites', 1);
    }

    public function test_adding_a_client_rejects_a_duplicate_phone_for_the_same_nutritionist(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        User::factory()->create(['phone' => '0501234567', 'nutritionist_id' => $nutritionist->id]);

        $response = $this->postJson('/api/v1/clients', [
            'name' => 'Another Client',
            'phone' => '0501234567',
            'goal' => 'weight_loss',
        ], $this->bearerFor($nutritionist));

        $response->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_the_same_phone_is_allowed_under_a_different_nutritionist(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $nutritionistB = User::factory()->nutritionist()->create();
        User::factory()->create(['phone' => '0501234567', 'nutritionist_id' => $nutritionistA->id]);

        $response = $this->postJson('/api/v1/clients', [
            'name' => 'Client Of B',
            'phone' => '0501234567',
            'goal' => 'weight_loss',
        ], $this->bearerFor($nutritionistB));

        $response->assertCreated();
    }

    public function test_client_codes_are_sequential_per_nutritionist(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $first = $this->postJson('/api/v1/clients', ['name' => 'A', 'phone' => '111', 'goal' => 'weight_loss'], $this->bearerFor($nutritionist));
        $second = $this->postJson('/api/v1/clients', ['name' => 'B', 'phone' => '222', 'goal' => 'weight_loss'], $this->bearerFor($nutritionist));

        $this->assertSame('PT-101', $first->json('client.code'));
        $this->assertSame('PT-102', $second->json('client.code'));
    }

    public function test_a_nutritionist_only_sees_their_own_clients_in_the_list(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $nutritionistB = User::factory()->nutritionist()->create();

        Subscriber::factory()->create(['nutritionist_id' => $nutritionistA->id]);
        Subscriber::factory()->count(2)->create(['nutritionist_id' => $nutritionistB->id]);

        $response = $this->getJson('/api/v1/clients', $this->bearerFor($nutritionistA));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_nutritionist_cannot_fetch_another_nutritionists_client_directly(): void
    {
        $nutritionistA = User::factory()->nutritionist()->create();
        $nutritionistB = User::factory()->nutritionist()->create();
        $othersClient = Subscriber::factory()->create(['nutritionist_id' => $nutritionistB->id]);

        $response = $this->getJson("/api/v1/clients/{$othersClient->id}", $this->bearerFor($nutritionistA));

        // Route-model-binding resolves through Subscriber's global scope
        // (NutritionistScope) — another nutritionist's client doesn't
        // exist as far as this query is concerned, so it 404s rather than
        // 403s (never confirms the ID even belongs to someone else).
        $response->assertNotFound();
    }

    public function test_a_client_role_user_cannot_manage_clients(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->client($nutritionist)->create();

        $response = $this->getJson('/api/v1/clients', $this->bearerFor($client));

        $response->assertForbidden();
    }

    public function test_list_can_be_filtered_by_status_and_searched_by_name(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $active = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]); // pending

        $active->user()->update(['name' => 'Findable Name']);

        $statusFiltered = $this->getJson('/api/v1/clients?status=active', $this->bearerFor($nutritionist));
        $this->assertCount(1, $statusFiltered->json('data'));
        $this->assertSame('active', $statusFiltered->json('data.0.status'));

        $searched = $this->getJson('/api/v1/clients?search=Findable', $this->bearerFor($nutritionist));
        $this->assertCount(1, $searched->json('data'));
    }
}
