<?php

namespace Tests\Feature\Auth;

use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Auth\EmailVerification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Audit BE-17: a stranger can sign up as a nutritionist, add patients and spend
 * the shared AI budget with no email check. Verification is required for
 * nutritionists created on or after EMAIL_VERIFICATION_REQUIRED_FROM; everyone
 * before that date is grandfathered (production's pilot nutritionists never
 * had a verified address), and with the setting empty nothing is enforced.
 */
class EmailVerificationTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function enforceFrom(string $date): void
    {
        config(['email_verification.required_from' => $date]);
    }

    private function nutritionist(string $createdAt, bool $verified = false): User
    {
        $user = User::factory()->nutritionist()->create(['email_verified_at' => $verified ? now() : null]);
        $user->forceFill(['created_at' => $createdAt])->save();

        return $user;
    }

    private function addPatient(User $nutritionist)
    {
        return $this->postJson('/api/v1/clients', ['name' => 'مريض تجريبي', 'phone' => '0599000111', 'goal' => 'health_monitoring'], $this->bearerFor($nutritionist));
    }

    public function test_nothing_is_enforced_while_the_setting_is_empty(): void
    {
        $this->assertNotSame(403, $this->addPatient($this->nutritionist('2026-12-05'))->status());
    }

    public function test_an_unverified_new_nutritionist_cannot_add_a_patient(): void
    {
        $this->enforceFrom('2026-12-01');

        $this->addPatient($this->nutritionist('2026-12-05'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_unverified');
    }

    public function test_nor_use_the_ai_endpoints(): void
    {
        $this->enforceFrom('2026-12-01');
        $user = $this->nutritionist('2026-12-05');
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $user->id]);
        $auth = $this->bearerFor($user);
        $plan = MealPlan::create(['subscriber_id' => $subscriber->id, 'created_by' => $user->id, 'status' => 'draft']);

        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/ai-draft", [], $auth)->assertStatus(403)->assertJsonPath('code', 'email_unverified');
        $this->postJson("/api/v1/clients/{$subscriber->id}/meal-plans/{$plan->id}/summary-suggestion", [], $auth)->assertStatus(403)->assertJsonPath('code', 'email_unverified');
        $this->postJson("/api/v1/clients/{$subscriber->id}/weight-insight/regenerate", [], $auth)->assertStatus(403)->assertJsonPath('code', 'email_unverified');
    }

    public function test_a_nutritionist_from_before_the_cutoff_keeps_working(): void
    {
        $this->enforceFrom('2026-12-01');

        $this->assertNotSame(403, $this->addPatient($this->nutritionist('2026-10-15'))->status(), 'a pilot nutritionist was locked out');
    }

    public function test_a_verified_nutritionist_passes(): void
    {
        $this->enforceFrom('2026-12-01');

        $this->assertNotSame(403, $this->addPatient($this->nutritionist('2026-12-05', verified: true))->status());
    }

    public function test_registering_sends_the_verification_mail_with_a_dashboard_link(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://dash.example.test']);

        $this->postJson('/api/v1/auth/register', ['name' => 'أخصائي', 'email' => 'new@example.test', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd', 'locale' => 'en'])->assertCreated();

        $user = User::where('email', 'new@example.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user) {
            $url = $n->verifyUrl($user);

            return str_starts_with($url, 'https://dash.example.test/en/verify-email?') && str_contains($url, "id={$user->id}") && str_contains($url, 'token=');
        });
    }

    public function test_a_mail_failure_does_not_break_registration(): void
    {
        $this->mock(EmailVerification::class)->shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/v1/auth/register', ['name' => 'أخصائي', 'email' => 'new2@example.test', 'password' => 'Str0ngPassw0rd', 'password_confirmation' => 'Str0ngPassw0rd'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new2@example.test']);
    }

    /** @return array{id: int, expires: int, token: string} */
    private function link(User $user, ?int $expires = null): array
    {
        $expires ??= now()->addDay()->timestamp;

        return ['id' => $user->id, 'expires' => $expires, 'token' => app(EmailVerification::class)->token($user, $expires)];
    }

    public function test_a_valid_link_verifies_the_address(): void
    {
        $user = $this->nutritionist('2026-12-05');

        $this->postJson('/api/v1/auth/email/verify', $this->link($user))->assertOk()->assertJsonPath('email_verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_bad_expired_or_foreign_link_does_not(): void
    {
        $user = $this->nutritionist('2026-12-05');
        $other = $this->nutritionist('2026-12-05');
        $link = $this->link($user);

        $this->postJson('/api/v1/auth/email/verify', ['token' => 'x'.$link['token']] + $link)->assertStatus(422)->assertJsonPath('code', 'verification_link_invalid');
        $this->postJson('/api/v1/auth/email/verify', $this->link($user, now()->subMinute()->timestamp))->assertStatus(422);
        $this->postJson('/api/v1/auth/email/verify', ['id' => $other->id] + $link)->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_resend_needs_sign_in_sends_once_a_minute_and_skips_a_verified_user(): void
    {
        Notification::fake();
        $user = $this->nutritionist('2026-12-05');

        $this->postJson('/api/v1/auth/email/resend')->assertStatus(401);
        $this->postJson('/api/v1/auth/email/resend', [], $this->bearerFor($user))->assertOk();
        $this->postJson('/api/v1/auth/email/resend', [], $this->bearerFor($user))->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 1);

        $verified = $this->nutritionist('2026-12-05', verified: true);
        $this->postJson('/api/v1/auth/email/resend', [], $this->bearerFor($verified))->assertOk();
        Notification::assertNotSentTo($verified, VerifyEmailNotification::class);
    }

    public function test_the_dashboard_can_read_the_status_from_me(): void
    {
        $this->enforceFrom('2026-12-01');
        $new = $this->nutritionist('2026-12-05');
        $old = $this->nutritionist('2026-10-15');

        $this->getJson('/api/v1/auth/me', $this->bearerFor($new))->assertJsonPath('email_verified', false)->assertJsonPath('email_verification_required', true);
        $this->getJson('/api/v1/auth/me', $this->bearerFor($old))->assertJsonPath('email_verified', false)->assertJsonPath('email_verification_required', false);
    }
}
