<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** S5-06 / FR-22: the client registering (or clearing) their device push token. */
class FcmTokenTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeClient(): User
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');

        return $client;
    }

    public function test_a_client_registers_their_device_token(): void
    {
        $client = $this->makeClient();

        $response = $this->putJson('/api/v1/me/fcm-token', ['fcm_token' => 'device-abc-123'], $this->bearerFor($client));

        $response->assertNoContent();
        $this->assertSame('device-abc-123', $client->fresh()->fcm_token);
    }

    /** Clearing it (logout, revoked permission) is a null send, not an error. */
    public function test_a_client_clears_their_device_token(): void
    {
        $client = $this->makeClient();
        $client->forceFill(['fcm_token' => 'device-abc-123'])->save();

        $this->putJson('/api/v1/me/fcm-token', ['fcm_token' => null], $this->bearerFor($client))
            ->assertNoContent();

        $this->assertNull($client->fresh()->fcm_token);
    }

    public function test_a_nutritionist_cannot_register_a_device_token(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();

        $this->putJson('/api/v1/me/fcm-token', ['fcm_token' => 'x'], $this->bearerFor($nutritionist))
            ->assertForbidden();
    }
}
