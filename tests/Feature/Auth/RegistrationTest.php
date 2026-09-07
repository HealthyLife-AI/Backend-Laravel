<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-1 / FR-01: nutritionist self-registration.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Nutri',
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ]);

        $response->assertCreated()->assertJsonStructure([
            'user' => ['id', 'name', 'email', 'role'],
            'access_token',
            'refresh_token',
            'token_type',
            'expires_in',
        ]);

        $this->assertSame('nutritionist', $response->json('user.role'));

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('nutritionist'));
        $this->assertNotSame('Passw0rd!', $user->password, 'password must be hashed');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->nutritionist()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Nutri',
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
            'password_confirmation' => 'Passw0rd!',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Nutri',
            'email' => 'jane@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
