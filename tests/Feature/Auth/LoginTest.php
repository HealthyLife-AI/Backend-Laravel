<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * US-01 / FR-01, FR-04: login, and account lockout after 5 consecutive
 * failed attempts.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The login route is also throttle-limited (routes/api.php); the
        // array cache store persists across tests in the same process, so
        // flush it to give each test its own rate-limit budget.
        Cache::flush();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_nutritionist_can_log_in_with_valid_credentials(): void
    {
        User::factory()->nutritionist()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Passw0rd!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    }

    public function test_invalid_credentials_are_rejected_without_revealing_which_field_was_wrong(): void
    {
        User::factory()->nutritionist()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Passw0rd!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertSame($response->json('message'), $unknownEmail->json('message'));
    }

    public function test_account_locks_after_five_consecutive_failed_attempts(): void
    {
        $user = User::factory()->nutritionist()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Passw0rd!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jane@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $user->refresh();
        $this->assertSame(5, $user->failed_login_attempts);
        $this->assertTrue($user->isLocked());

        // Even the correct password is rejected while locked.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
        ]);

        $response->assertStatus(423)->assertJsonStructure(['message', 'locked_until']);
    }

    public function test_a_successful_login_resets_the_failed_attempt_counter(): void
    {
        $user = User::factory()->nutritionist()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Passw0rd!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Passw0rd!',
        ])->assertOk();

        $this->assertSame(0, $user->refresh()->failed_login_attempts);
    }
}
