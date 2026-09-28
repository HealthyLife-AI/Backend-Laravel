<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Forgot / reset password (POST /auth/forgot-password, /auth/reset-password).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['app.frontend_url' => 'https://app.example.com']);
    }

    public function test_forgot_password_emails_a_reset_link_into_the_frontend_on_the_requested_locale(): void
    {
        Notification::fake();
        $user = User::factory()->nutritionist()->create(['email' => 'amal@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Amal@Example.com', 'locale' => 'en'])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->resetUrl($user);

            return str_starts_with($url, 'https://app.example.com/en/reset-password?')
                && str_contains($url, 'email=amal%40example.com')
                && str_contains($url, 'token=');
        });
    }

    public function test_forgot_password_answers_the_same_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password_and_revokes_every_session(): void
    {
        $user = User::factory()->nutritionist()->create(['email' => 'amal@example.com', 'password' => 'OldPass123']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'amal@example.com', 'password' => 'OldPass123'])->assertOk();
        $oldRefresh = $login->json('refresh_token');
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'amal@example.com',
            'token' => $token,
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPass456', $user->fresh()->password));
        $this->postJson('/api/v1/auth/login', ['email' => 'amal@example.com', 'password' => 'NewPass456'])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])->assertUnauthorized();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        User::factory()->nutritionist()->create(['email' => 'amal@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'amal@example.com',
            'token' => 'not-a-real-token',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ])->assertUnprocessable();
    }

    public function test_the_new_password_must_meet_the_registration_policy(): void
    {
        $user = User::factory()->nutritionist()->create(['email' => 'amal@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'amal@example.com',
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
