<?php

namespace Tests\Feature\Auth;

use App\Models\Subscriber;
use App\Models\User;
use App\Services\Clients\ClientInviteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Part A: the nutritionist gives the patient a username and the system
 * generates the password; the patient signs in with them. No invite links.
 */
class PatientCredentialsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
    }

    private function add(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->postJson('/api/v1/clients', $overrides + [
            'name' => 'سارة',
            'phone' => '+970599000001',
            'username' => 'sara.k',
            'goal' => 'weight_loss',
        ], $this->bearerFor($as ?? $this->nutritionist));
    }

    private function login(array $body): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', $body);
    }

    // ---- create -------------------------------------------------------------

    public function test_create_returns_the_credentials_once_and_stores_only_the_hash(): void
    {
        $response = $this->add(['username' => '  Sara.K '])->assertCreated();
        $password = $response->json('credentials.password');

        $response->assertJsonPath('credentials.username', 'sara.k')->assertJsonPath('client.username', 'sara.k');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-np-z2-9]{10}$/', $password);

        $user = User::where('username', 'sara.k')->firstOrFail();
        $this->assertNotSame($password, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->password_is_temporary);

        // Nowhere else: not on the patient's record, not in the roster.
        $id = $response->json('client.id');
        $this->assertStringNotContainsString($password, $this->getJson("/api/v1/clients/{$id}", $this->bearerFor($this->nutritionist))->getContent());
        $this->assertStringNotContainsString($password, $this->getJson('/api/v1/clients', $this->bearerFor($this->nutritionist))->getContent());
    }

    public function test_no_invite_token_is_issued_any_more(): void
    {
        $this->add()->assertCreated()->assertJsonMissingPath('invite_token')->assertJsonMissingPath('invite_expires_at');

        $this->assertDatabaseCount('client_invites', 0);
    }

    public function test_a_taken_username_is_a_422_that_says_only_that(): void
    {
        $this->add()->assertCreated();
        $other = User::factory()->nutritionist()->create();

        $this->add(['phone' => '+970599000002', 'username' => 'SARA.K'], $other)
            ->assertUnprocessable()
            ->assertJsonPath('errors.username.0', 'This username is taken.');
    }

    public function test_invalid_and_arabic_usernames_are_refused(): void
    {
        $this->add(['username' => 'ab'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->add(['username' => 'sara k'])->assertUnprocessable()->assertJsonValidationErrors('username');
        $arabic = $this->add(['username' => 'سارة'])->assertUnprocessable();
        $this->assertStringContainsString('no Arabic letters', $arabic->json('errors.username.0'));
        $this->add(['username' => null])->assertUnprocessable()->assertJsonValidationErrors('username');
    }

    public function test_arabic_indic_digits_are_normalised(): void
    {
        $this->add(['username' => 'ALI٢٠٢٦'])->assertCreated()->assertJsonPath('credentials.username', 'ali2026');
    }

    // ---- login ----------------------------------------------------------------

    public function test_login_by_username_in_any_case_and_with_arabic_digits(): void
    {
        $password = $this->add(['username' => 'ali2026'])->json('credentials.password');

        $this->login(['username' => 'ali2026', 'password' => $password])->assertOk();
        $this->login(['username' => ' ALI2026 ', 'password' => $password])->assertOk();
        $this->login(['username' => 'Ali٢٠٢٦', 'password' => $password])->assertOk()->assertJsonPath('user.username', 'ali2026');
        $this->login(['username' => 'ali2026', 'password' => 'wrong'])->assertUnauthorized();
        $this->login(['username' => 'nobody.here', 'password' => $password])->assertUnauthorized();
    }

    public function test_the_first_login_activates_a_pending_patient(): void
    {
        $response = $this->add();
        $subscriber = Subscriber::findOrFail($response->json('client.id'));
        $this->assertSame('pending', $subscriber->status);
        $this->assertNull($subscriber->activated_at);

        $this->freezeTime();
        $this->login(['username' => 'sara.k', 'password' => $response->json('credentials.password')])->assertOk();

        $subscriber->refresh();
        $this->assertSame('active', $subscriber->status);
        $this->assertSame(now()->timestamp, $subscriber->activated_at->timestamp);
    }

    public function test_a_failed_login_does_not_activate(): void
    {
        $response = $this->add();
        $this->login(['username' => 'sara.k', 'password' => 'wrong'])->assertUnauthorized();

        $this->assertSame('pending', Subscriber::findOrFail($response->json('client.id'))->status);
    }

    public function test_nutritionists_still_sign_in_by_email(): void
    {
        $this->nutritionist->forceFill(['password' => 'NutPass123'])->save();

        $this->login(['email' => $this->nutritionist->email, 'password' => 'NutPass123'])->assertOk();
    }

    // TEMPORARY: remove with the phone login path once the patient app ships username login.
    public function test_legacy_phone_login_still_works(): void
    {
        $password = $this->add()->json('credentials.password');

        $this->login(['phone' => '+970599000001', 'password' => $password])->assertOk()->assertJsonPath('user.username', 'sara.k');
    }

    public function test_a_shared_phone_checks_the_password_against_each_account(): void
    {
        $other = User::factory()->nutritionist()->create();
        $first = $this->add()->json('credentials.password');
        $second = $this->add(['username' => 'sara.two'], $other)->json('credentials.password');

        $this->login(['phone' => '+970599000001', 'password' => $first])->assertOk()->assertJsonPath('user.username', 'sara.k');
        $this->login(['phone' => '+970599000001', 'password' => $second])->assertOk()->assertJsonPath('user.username', 'sara.two');
    }

    public function test_a_wrong_password_on_a_shared_phone_counts_against_every_account(): void
    {
        $other = User::factory()->nutritionist()->create();
        $this->add();
        $this->add(['username' => 'sara.two'], $other);

        $this->login(['phone' => '+970599000001', 'password' => 'wrong'])->assertUnauthorized();

        $this->assertSame([1, 1], User::whereIn('username', ['sara.k', 'sara.two'])->orderBy('id')->pluck('failed_login_attempts')->all());
    }

    public function test_the_lockout_still_applies_to_username_login(): void
    {
        $password = $this->add()->json('credentials.password');

        for ($i = 0; $i < config('jwt.max_login_attempts'); $i++) {
            $this->login(['username' => 'sara.k', 'password' => 'wrong']);
        }

        $this->login(['username' => 'sara.k', 'password' => $password])->assertStatus(423);
    }

    // ---- reset ------------------------------------------------------------------

    public function test_reset_requires_a_username_for_a_patient_who_has_none(): void
    {
        $old = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $url = "/api/v1/clients/{$old->id}/reset-password";

        $this->postJson($url, [], $this->bearerFor($this->nutritionist))->assertUnprocessable()->assertJsonValidationErrors('username');

        $credentials = $this->postJson($url, ['username' => 'Old.Patient'], $this->bearerFor($this->nutritionist))->assertOk()->json();

        $this->assertSame('old.patient', $credentials['username']);
        $this->login($credentials)->assertOk()->assertJsonPath('user.password_is_temporary', true);
    }

    public function test_reset_can_change_the_username_but_not_to_a_taken_one(): void
    {
        $id = $this->add()->json('client.id');
        $this->add(['phone' => '+970599000002', 'username' => 'taken.name']);
        $url = "/api/v1/clients/{$id}/reset-password";

        $this->postJson($url, ['username' => 'TAKEN.NAME'], $this->bearerFor($this->nutritionist))
            ->assertUnprocessable()->assertJsonPath('errors.username.0', 'This username is taken.');
        // Its own name is not "taken".
        $this->postJson($url, ['username' => 'sara.k'], $this->bearerFor($this->nutritionist))->assertOk();
        $this->postJson($url, ['username' => 'sara.new'], $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('username', 'sara.new');
        // Without a username the current one stays.
        $this->postJson($url, [], $this->bearerFor($this->nutritionist))->assertOk()->assertJsonPath('username', 'sara.new');
    }

    public function test_reset_clears_a_lockout_and_sets_the_flag_again(): void
    {
        $id = $this->add()->json('client.id');
        $user = User::where('username', 'sara.k')->firstOrFail();
        $user->forceFill(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes(15), 'password_is_temporary' => false])->save();

        $credentials = $this->postJson("/api/v1/clients/{$id}/reset-password", [], $this->bearerFor($this->nutritionist))->json();

        $this->login($credentials)->assertOk()->assertJsonPath('user.password_is_temporary', true);
    }

    public function test_the_generated_password_is_never_logged(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });

        $created = $this->add()->json();
        $reset = $this->postJson("/api/v1/clients/{$created['client']['id']}/reset-password", [], $this->bearerFor($this->nutritionist))->json();

        foreach ([$created['credentials']['password'], $reset['password']] as $password) {
            foreach ($logged as $line) {
                $this->assertStringNotContainsString($password, $line);
            }
        }
    }

    // ---- old tokens -------------------------------------------------------------

    public function test_an_already_issued_activation_token_still_works_and_activates(): void
    {
        $pending = Subscriber::factory()->forNutritionist($this->nutritionist)->create();
        $pending->user->forceFill(['password_is_temporary' => true])->save();
        $token = app(ClientInviteService::class)->issue($pending)['plain'];

        $this->postJson("/api/v1/invites/{$token}/activate", ['password' => 'Chosen789A', 'password_confirmation' => 'Chosen789A'])
            ->assertOk()
            ->assertJsonPath('user.password_is_temporary', false);

        $pending->refresh();
        $this->assertSame('active', $pending->status);
        $this->assertNotNull($pending->activated_at);
    }
}
