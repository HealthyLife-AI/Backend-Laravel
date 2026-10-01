<?php

namespace Tests\Feature\Auth;

use App\Models\ClientInvite;
use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Batch A: PUT /me/password, the nutritionist-issued sign-in link, and the
 * patient-app fields on /auth/me, /me/nutritionist and follow_up_ended.
 */
class PatientAccountAccessTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create(['name' => 'أ. ليلى حسن']);
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->patient->user->forceFill(['password' => Hash::make('OldPass123'), 'phone' => '+15555550199'])->save();
    }

    private function login(string $password = 'OldPass123'): array
    {
        return $this->postJson('/api/v1/auth/login', ['phone' => '+15555550199', 'password' => $password])->assertOk()->json();
    }

    private function bearer(string $access): array
    {
        return ['Authorization' => "Bearer {$access}"];
    }

    public function test_the_fresh_pair_works_and_old_tokens_die_within_the_same_second(): void
    {
        // Clock frozen: login, password change and every later request share one second.
        $this->freezeTime();

        $old = $this->login();

        $fresh = $this->putJson('/api/v1/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ], $this->bearer($old['access_token']))->assertOk()->json();

        $this->getJson('/api/v1/auth/me', $this->bearer($fresh['access_token']))->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $fresh['refresh_token']])->assertOk();
        $this->getJson('/api/v1/auth/me', $this->bearer($old['access_token']))->assertUnauthorized();
    }

    public function test_a_sign_in_link_activated_in_the_same_second_ends_older_tokens(): void
    {
        $this->freezeTime();

        $old = $this->login();
        $activated = $this->activate($this->issueLink())->assertOk()->json();

        $this->getJson('/api/v1/auth/me', $this->bearer($activated['access_token']))->assertOk();
        $this->getJson('/api/v1/auth/me', $this->bearer($old['access_token']))->assertUnauthorized();
    }

    public function test_the_second_password_change_in_one_second_still_ends_the_first_pair(): void
    {
        $this->freezeTime();
        $session = $this->login();

        $first = $this->putJson('/api/v1/me/password', ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'], $this->bearer($session['access_token']))->assertOk()->json();
        $second = $this->putJson('/api/v1/me/password', ['current_password' => 'NewPass456', 'password' => 'Newer789A', 'password_confirmation' => 'Newer789A'], $this->bearer($first['access_token']))->assertOk()->json();

        $this->getJson('/api/v1/auth/me', $this->bearer($first['access_token']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->bearer($second['access_token']))->assertOk();
    }

    // ---- PUT /me/password ------------------------------------------------

    public function test_changing_password_ends_other_sessions_and_keeps_this_device_signed_in(): void
    {
        $other = $this->login();
        $mine = $this->login();

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ], $this->bearer($mine['access_token']))->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'user']);

        // Every earlier access token and refresh token is dead, this device's included.
        $this->getJson('/api/v1/auth/me', $this->bearer($other['access_token']))->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->bearer($mine['access_token']))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $other['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $mine['refresh_token']])->assertUnauthorized();

        // The pair returned with the change works.
        $this->getJson('/api/v1/auth/me', $this->bearer($response->json('access_token')))->assertOk();
        $this->postJson('/api/v1/auth/login', ['phone' => '+15555550199', 'password' => 'NewPass456'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['phone' => '+15555550199', 'password' => 'OldPass123'])->assertUnauthorized();
    }

    public function test_a_wrong_current_password_or_a_weak_new_one_is_refused(): void
    {
        $session = $this->login();

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'wrong',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ], $this->bearer($session['access_token']))->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'short',
            'password_confirmation' => 'short',
        ], $this->bearer($session['access_token']))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->getJson('/api/v1/auth/me', $this->bearer($session['access_token']))->assertOk();
    }

    public function test_changing_password_is_throttled_like_login(): void
    {
        $session = $this->login();

        for ($i = 0; $i < 10; $i++) {
            $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'], $this->bearer($session['access_token']));
        }

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'], $this->bearer($session['access_token']))
            ->assertStatus(429);
    }

    // ---- sign-in link ------------------------------------------------------

    private function issueLink(?User $as = null): string
    {
        return $this->postJson("/api/v1/clients/{$this->patient->id}/sign-in-link", [], $this->bearerFor($as ?? $this->nutritionist))
            ->assertCreated()
            ->json('token');
    }

    private function activate(string $token): TestResponse
    {
        return $this->postJson("/api/v1/invites/{$token}/activate", ['password' => 'Fresh789A', 'password_confirmation' => 'Fresh789A']);
    }

    public function test_the_sign_in_link_sets_a_new_password_and_ends_old_sessions(): void
    {
        $old = $this->login();

        $token = $this->issueLink();
        $activated = $this->activate($token)->assertOk()->json();

        $this->getJson('/api/v1/auth/me', $this->bearer($old['access_token']))->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $old['refresh_token']])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $this->bearer($activated['access_token']))->assertOk();
        $this->postJson('/api/v1/auth/login', ['phone' => '+15555550199', 'password' => 'Fresh789A'])->assertOk();
    }

    /**
     * An old refresh token must not mint a token that carries the new session
     * version, and a stale device presenting one must not trip theft detection
     * and log out the session that was just opened.
     */
    public function test_after_a_password_change_old_refresh_tokens_mint_nothing_and_cost_the_new_session_nothing(): void
    {
        $old = $this->login();

        $fresh = $this->putJson('/api/v1/me/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ], $this->bearer($old['access_token']))->assertOk()->json();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $old['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonMissingPath('access_token');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $fresh['refresh_token']])->assertOk();
    }

    public function test_after_a_sign_in_link_old_refresh_tokens_mint_nothing_and_cost_the_new_session_nothing(): void
    {
        $old = $this->login();
        $activated = $this->activate($this->issueLink())->assertOk()->json();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $old['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonMissingPath('access_token');

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $activated['refresh_token']])->assertOk();
    }

    public function test_the_sign_in_link_works_once(): void
    {
        $token = $this->issueLink();

        $this->activate($token)->assertOk();
        $this->activate($token)->assertUnprocessable();
    }

    public function test_the_sign_in_link_expires_like_an_invite(): void
    {
        $token = $this->issueLink();

        $this->travel(config('invite.ttl_days'))->days();
        $this->travel(1)->minutes();

        $this->activate($token)->assertUnprocessable();
    }

    public function test_a_new_link_replaces_the_previous_unused_one(): void
    {
        $first = $this->issueLink();
        $second = $this->issueLink();

        $this->activate($first)->assertUnprocessable();
        $this->activate($second)->assertOk();
    }

    public function test_another_nutritionist_cannot_issue_a_link(): void
    {
        $stranger = User::factory()->nutritionist()->create();

        $this->postJson("/api/v1/clients/{$this->patient->id}/sign-in-link", [], $this->bearerFor($stranger))->assertNotFound();
        $this->postJson("/api/v1/clients/{$this->patient->id}/sign-in-link", [], $this->bearerFor($this->patient->user))->assertForbidden();
        $this->assertSame(0, ClientInvite::where('subscriber_id', $this->patient->id)->count());
    }

    public function test_no_link_for_a_patient_whose_follow_up_ended(): void
    {
        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->bearerFor($this->nutritionist))->assertOk();

        $this->postJson("/api/v1/clients/{$this->patient->id}/sign-in-link", [], $this->bearerFor($this->nutritionist))
            ->assertStatus(409);
    }

    // ---- patient-app fields ---------------------------------------------------

    public function test_auth_me_carries_the_patient_code(): void
    {
        $this->getJson('/api/v1/auth/me', $this->bearerFor($this->patient->user))
            ->assertOk()
            ->assertJsonPath('patient_code', $this->patient->code);
    }

    public function test_my_nutritionist_carries_bio_and_reply_hours(): void
    {
        $this->putJson('/api/v1/me/nutritionist-profile', [
            'bio' => 'أتابع مرضاي يوميًا.',
            'reply_hours' => 'الأحد–الخميس 9–5',
        ], $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('reply_hours', 'الأحد–الخميس 9–5');

        $this->getJson('/api/v1/me/nutritionist', $this->bearerFor($this->patient->user))
            ->assertOk()
            ->assertJsonPath('bio', 'أتابع مرضاي يوميًا.')
            ->assertJsonPath('reply_hours', 'الأحد–الخميس 9–5');
    }

    public function test_follow_up_ended_carries_what_the_ended_screen_needs(): void
    {
        $this->nutritionist->nutritionistProfile()->create(['whatsapp_number' => '+15555550100']);
        $this->patient->forceFill(['created_at' => now()->subWeeks(12)->subDays(3)])->save();
        $food = Food::factory()->create();
        MealLog::create(['subscriber_id' => $this->patient->id, 'food_id' => $food->id, 'quantity_grams' => 100, 'logged_at' => now()->subDays(10)]);

        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->bearerFor($this->nutritionist))->assertOk();

        $response = $this->postJson('/api/v1/auth/login', ['phone' => '+15555550199', 'password' => 'OldPass123'])
            ->assertForbidden()
            ->assertJsonPath('code', 'follow_up_ended')
            ->assertJsonPath('nutritionist.name', 'أ. ليلى حسن')
            ->assertJsonPath('nutritionist.whatsapp_number', '+15555550100')
            ->assertJsonPath('weeks_followed', 12)
            ->assertJsonPath('adherence_percent', fn ($v) => (float) $v === 0.0);

        $this->assertEqualsCanonicalizing(
            ['message', 'code', 'nutritionist', 'weeks_followed', 'adherence_percent'],
            array_keys($response->json()),
        );
    }
}
