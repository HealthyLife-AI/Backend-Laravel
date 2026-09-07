<?php

namespace Tests\Feature\Clients;

use App\Models\Subscriber;
use App\Services\Clients\ClientInviteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** FR-03 / BR-3: single-use invite-link activation. */
class ClientInviteActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function issueInvite(): array
    {
        $subscriber = Subscriber::factory()->create();
        $invite = app(ClientInviteService::class)->issue($subscriber);

        return [$subscriber, $invite['plain']];
    }

    public function test_a_client_can_activate_with_a_valid_token_and_is_logged_in(): void
    {
        [$subscriber, $token] = $this->issueInvite();

        $response = $this->postJson("/api/v1/invites/{$token}/activate", [
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ]);

        $response->assertOk()->assertJsonStructure([
            'user' => ['id', 'name', 'phone', 'role'],
            'access_token',
            'refresh_token',
            'token_type',
            'expires_in',
        ]);

        $this->assertSame('client', $response->json('user.role'));
        $this->assertSame('active', $subscriber->fresh()->status);
        $this->assertTrue(Hash::check('Passw0rd!', $subscriber->user->fresh()->password));
    }

    public function test_an_already_used_token_is_rejected(): void
    {
        [, $token] = $this->issueInvite();

        $this->postJson("/api/v1/invites/{$token}/activate", [
            'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!',
        ])->assertOk();

        $this->postJson("/api/v1/invites/{$token}/activate", [
            'password' => 'Different1!', 'password_confirmation' => 'Different1!',
        ])->assertUnprocessable();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $subscriber = Subscriber::factory()->create();
        $invite = app(ClientInviteService::class)->issue($subscriber);
        $invite['model']->update(['expires_at' => now()->subDay()]);

        $this->postJson("/api/v1/invites/{$invite['plain']}/activate", [
            'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!',
        ])->assertUnprocessable();
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->postJson('/api/v1/invites/not-a-real-token/activate', [
            'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!',
        ])->assertUnprocessable();
    }

    public function test_the_client_can_immediately_call_me_with_the_returned_access_token(): void
    {
        [, $token] = $this->issueInvite();

        $activation = $this->postJson("/api/v1/invites/{$token}/activate", [
            'password' => 'Passw0rd!', 'password_confirmation' => 'Passw0rd!',
        ]);

        $accessToken = $activation->json('access_token');

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$accessToken}"])
            ->assertOk()
            ->assertJsonPath('role', 'client');
    }
}
