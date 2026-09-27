<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Continue with Google" (POST /auth/google). Google itself is faked:
 * the test controls what tokeninfo/userinfo answer for a given token.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.google.client_id' => 'test-client-id.apps.googleusercontent.com']);
    }

    private function fakeGoogle(array $tokeninfo = [], array $userinfo = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($tokeninfo + [
                'aud' => 'test-client-id.apps.googleusercontent.com',
                'sub' => '1234567890',
                'email' => 'Amal@Example.com',
                'email_verified' => 'true',
                'expires_in' => 3000,
            ]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response($userinfo + [
                'sub' => '1234567890',
                'name' => 'Dr. Amal',
                'email' => 'amal@example.com',
                'picture' => 'https://lh3.googleusercontent.com/a/photo',
            ]),
        ]);
    }

    public function test_a_new_google_account_becomes_a_nutritionist_and_is_signed_in(): void
    {
        $this->fakeGoogle();

        $response = $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.valid']);

        $response->assertOk()->assertJsonStructure(['user', 'access_token', 'refresh_token']);
        $this->assertSame('nutritionist', $response->json('user.role'));
        $this->assertSame('amal@example.com', $response->json('user.email'));

        $user = User::where('email', 'amal@example.com')->firstOrFail();
        $this->assertSame('1234567890', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('nutritionist'));
    }

    public function test_an_existing_password_account_with_the_same_verified_email_is_linked_not_duplicated(): void
    {
        $existing = User::factory()->nutritionist()->create(['email' => 'amal@example.com']);
        $this->fakeGoogle();

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.valid'])->assertOk();

        $this->assertSame(1, User::where('email', 'amal@example.com')->count());
        $this->assertSame('1234567890', $existing->fresh()->google_id);
    }

    public function test_a_token_issued_for_another_app_is_rejected(): void
    {
        $this->fakeGoogle(['aud' => 'someone-else.apps.googleusercontent.com']);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.other'])->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.unverified'])->assertUnauthorized();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'garbage'])->assertUnauthorized();
    }

    public function test_it_answers_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.valid'])->assertStatus(503);
    }

    public function test_a_locked_account_stays_locked_through_google(): void
    {
        User::factory()->nutritionist()->create(['email' => 'amal@example.com', 'locked_until' => now()->addMinutes(10)]);
        $this->fakeGoogle();

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.valid'])->assertStatus(423);
    }
}
