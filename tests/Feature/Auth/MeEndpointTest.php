<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\JwtService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the `jwt` middleware (App\Http\Middleware\JwtAuthenticate)
 * correctly authenticates a request from its Bearer token.
 */
class MeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_rejects_a_request_without_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_it_rejects_a_malformed_token(): void
    {
        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer not-a-real-jwt',
        ])->assertUnauthorized();
    }

    public function test_it_returns_the_authenticated_user_for_a_valid_token(): void
    {
        $user = User::factory()->nutritionist()->create();
        $token = app(JwtService::class)->issueAccessToken($user);

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer {$token}",
        ]);

        // Flat, not {"data": {...}} — JsonResource::withoutWrapping() in
        // AppServiceProvider keeps every resource response consistent
        // with the embedded-resource shape login/register already return.
        $response->assertOk()->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('role', 'nutritionist');
    }
}
