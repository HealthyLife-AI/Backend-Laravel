<?php

namespace Tests\Feature\Consent;

use App\Models\PatientConsent;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-17: a patient must accept the current privacy-policy version before
 * their data endpoints open; each acceptance is recorded with the version,
 * time, IP and user agent.
 */
class ConsentTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    private const VERSION = '2026-10-01';

    private const POLICY = 'https://dashboard.example/privacy';

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['patient_app.consent.version' => self::VERSION, 'patient_app.consent.policy_url' => self::POLICY]);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->client->assignRole('client');
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
    }

    /** This test is about consent itself: patients start without it. */
    protected function acceptsConsentForPatients(): bool
    {
        return false;
    }

    private function auth(?User $as = null): array
    {
        return $this->bearerFor($as ?? $this->client);
    }

    private function accept(): void
    {
        $this->postJson('/api/v1/me/consent', [], $this->auth())->assertSuccessful();
    }

    /** Every patient data endpoint the gate must close: [method, uri]. */
    private function gatedRequests(): array
    {
        return [
            ['GET', '/api/v1/me/meal-plan'],
            ['GET', '/api/v1/me/meal-logs'],
            ['POST', '/api/v1/me/meal-logs'],
            ['PATCH', '/api/v1/me/meal-logs/1'],
            ['DELETE', '/api/v1/me/meal-logs/1'],
            ['GET', '/api/v1/me/measurements'],
            ['POST', '/api/v1/me/measurements'],
            ['DELETE', '/api/v1/me/measurements/1'],
            ['GET', '/api/v1/me/adherence'],
            ['GET', '/api/v1/me/progress'],
            ['GET', '/api/v1/foods/search?q=rice'],
        ];
    }

    // ---- status and acceptance --------------------------------------------

    public function test_status_says_acceptance_is_required_until_it_is_given(): void
    {
        $this->getJson('/api/v1/me/consent', $this->auth())->assertOk()->assertExactJson([
            'required' => true,
            'current_version' => self::VERSION,
            'accepted_version' => null,
            'accepted_at' => null,
            'policy_url' => self::POLICY,
        ]);
    }

    public function test_accepting_records_version_time_ip_and_user_agent(): void
    {
        $response = $this->withHeader('User-Agent', 'HealthyLifeApp/1.0 (Android 14)')
            ->postJson('/api/v1/me/consent', [], $this->auth())
            ->assertCreated()
            ->assertJsonPath('required', false)
            ->assertJsonPath('accepted_version', self::VERSION);

        $this->assertNotNull($response->json('accepted_at'));
        $row = PatientConsent::firstOrFail();
        $this->assertSame($this->client->id, $row->user_id);
        $this->assertSame(self::VERSION, $row->version);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('HealthyLifeApp/1.0 (Android 14)', $row->user_agent);
        $this->assertNotNull($row->accepted_at);

        $this->getJson('/api/v1/me/consent', $this->auth())->assertJsonPath('required', false)->assertJsonPath('accepted_version', self::VERSION);
    }

    public function test_accepting_again_is_a_200_and_keeps_the_first_record(): void
    {
        $this->postJson('/api/v1/me/consent', [], $this->auth())->assertCreated();

        PatientConsent::query()->update(['accepted_at' => now()->subDays(2)]);
        $first = PatientConsent::firstOrFail();

        $this->postJson('/api/v1/me/consent', ['version' => self::VERSION], $this->auth())->assertOk()->assertJsonPath('required', false);

        $this->assertSame(1, PatientConsent::count());
        $this->assertEquals($first->accepted_at->timestamp, PatientConsent::first()->accepted_at->timestamp);
    }

    public function test_a_version_the_patient_was_not_shown_is_refused(): void
    {
        $this->postJson('/api/v1/me/consent', ['version' => '2025-01-01'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('code', 'consent_version_mismatch')
            ->assertJsonPath('current_version', self::VERSION);

        $this->assertSame(0, PatientConsent::count());
    }

    public function test_a_new_policy_version_asks_everyone_again_and_keeps_the_history(): void
    {
        $this->accept();

        config(['patient_app.consent.version' => '2027-02-01']);

        $this->getJson('/api/v1/me/consent', $this->auth())
            ->assertOk()
            ->assertJsonPath('required', true)
            ->assertJsonPath('current_version', '2027-02-01')
            ->assertJsonPath('accepted_version', self::VERSION);
        $this->getJson('/api/v1/me/meal-logs', $this->auth())->assertForbidden()->assertJsonPath('code', 'consent_required');

        $this->accept();
        $this->assertSame(2, PatientConsent::count(), 'the old acceptance is kept, not overwritten');
        $this->getJson('/api/v1/me/meal-logs', $this->auth())->assertOk();
    }

    public function test_one_patients_acceptance_does_not_open_another_patients_data(): void
    {
        $this->accept();
        $other = User::factory()->create(['nutritionist_id' => $this->nutritionist->id]);
        $other->assignRole('client');
        Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $other->id]);

        $this->getJson('/api/v1/me/meal-logs', $this->auth($other))->assertForbidden()->assertJsonPath('code', 'consent_required');
    }

    // ---- the gate ---------------------------------------------------------

    public function test_every_patient_data_endpoint_is_closed_until_consent_is_accepted(): void
    {
        foreach ($this->gatedRequests() as [$method, $uri]) {
            $this->json($method, $uri, [], $this->auth())
                ->assertForbidden()
                ->assertJsonPath('code', 'consent_required')
                ->assertJsonPath('current_version', self::VERSION)
                ->assertJsonPath('policy_url', self::POLICY);
        }
    }

    public function test_after_accepting_the_same_endpoints_are_no_longer_refused_for_consent(): void
    {
        $this->accept();

        foreach ($this->gatedRequests() as [$method, $uri]) {
            $response = $this->json($method, $uri, [], $this->auth());

            $this->assertNotSame('consent_required', $response->getContent() === '' ? null : $response->json('code'), "{$method} {$uri} is still gated");
        }
    }

    public function test_the_exempt_endpoints_work_without_consent(): void
    {
        $token = $this->auth();

        $this->getJson('/api/v1/auth/me', $token)->assertOk();
        $this->getJson('/api/v1/me/nutritionist', $token)->assertOk();
        $this->putJson('/api/v1/me/fcm-token', ['token' => str_repeat('a', 40)], $token)->assertSuccessful();
        $this->getJson('/api/v1/me/consent', $token)->assertOk();
    }

    public function test_login_and_refresh_do_not_need_consent(): void
    {
        $this->client->forceFill(['phone' => '+970590000077', 'password' => bcrypt('patient-pass')])->save();

        $login = $this->postJson('/api/v1/auth/login', ['phone' => '+970590000077', 'password' => 'patient-pass'])->assertOk();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $login->json('refresh_token')])->assertOk();
    }

    public function test_follow_up_ended_takes_precedence_over_consent_required(): void
    {
        $token = $this->auth();
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        foreach ($this->gatedRequests() as [$method, $uri]) {
            $this->json($method, $uri, [], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        }
        $this->getJson('/api/v1/me/consent', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->postJson('/api/v1/me/consent', [], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->assertSame(0, PatientConsent::count());
    }

    public function test_a_nutritionist_cannot_use_the_consent_endpoints_and_is_not_gated(): void
    {
        $token = $this->auth($this->nutritionist);

        $this->getJson('/api/v1/me/consent', $token)->assertForbidden();
        $this->postJson('/api/v1/me/consent', [], $token)->assertForbidden();
        $this->getJson('/api/v1/me/meal-logs', $token)->assertForbidden();
        // A shared route is not affected by the patient gate.
        $this->getJson('/api/v1/foods/search?q=rice', $token)->assertOk();
        $this->getJson('/api/v1/clients', $token)->assertOk();
        $this->assertSame(0, PatientConsent::count());
    }

    public function test_the_unauthenticated_are_refused(): void
    {
        $this->getJson('/api/v1/me/consent')->assertUnauthorized();
        $this->postJson('/api/v1/me/consent')->assertUnauthorized();
    }

    // ---- configuration: never unconfigured ----------------------------------

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $savedEnv = [];

    /**
     * Re-reads config/patient_app.php with the given environment, the way a
     * deployment would boot with it. Variables not listed are unset.
     *
     * @param  array<string, string>  $env
     */
    private function bootConsentConfigWith(array $env): void
    {
        foreach (['CONSENT_VERSION', 'CONSENT_POLICY_URL', 'FRONTEND_URL'] as $key) {
            $this->savedEnv[$key] ??= [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

            if (array_key_exists($key, $env)) {
                putenv("{$key}={$env[$key]}");
                $_ENV[$key] = $_SERVER[$key] = $env[$key];
            } else {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        config(['patient_app.consent' => (require config_path('patient_app.php'))['consent']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$getenv, $env, $server]) {
            $getenv === false ? putenv($key) : putenv("{$key}={$getenv}");
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }

        parent::tearDown();
    }

    public function test_with_no_env_values_at_all_the_gate_still_works_on_a_default_version(): void
    {
        $this->bootConsentConfigWith([]);
        $this->app['env'] = 'production';
        $token = $this->auth();

        $this->getJson('/api/v1/me/consent', $token)->assertOk()->assertExactJson([
            'required' => true,
            'current_version' => '2026-10-01',
            'accepted_version' => null,
            'accepted_at' => null,
            'policy_url' => null,
        ]);
        foreach ($this->gatedRequests() as [$method, $uri]) {
            $this->json($method, $uri, [], $token)
                ->assertForbidden()
                ->assertJsonPath('code', 'consent_required')
                ->assertJsonPath('current_version', '2026-10-01')
                ->assertJsonPath('policy_url', null);
        }

        $this->postJson('/api/v1/me/consent', ['version' => '2026-10-01'], $token)->assertCreated()->assertJsonPath('required', false);
        $this->getJson('/api/v1/me/meal-logs', $token)->assertOk();
    }

    public function test_a_blank_version_uses_the_default_and_never_answers_503(): void
    {
        $this->bootConsentConfigWith(['CONSENT_VERSION' => '', 'CONSENT_POLICY_URL' => '']);

        foreach (['production', 'staging', 'local'] as $env) {
            $this->app['env'] = $env;
            $this->getJson('/api/v1/me/meal-logs', $this->auth())
                ->assertForbidden()->assertJsonPath('code', 'consent_required')->assertJsonPath('current_version', '2026-10-01');
        }
    }

    public function test_the_policy_url_defaults_to_the_privacy_page_on_the_frontend_url(): void
    {
        $this->bootConsentConfigWith(['FRONTEND_URL' => 'https://dashboard.example/']);
        $this->getJson('/api/v1/me/consent', $this->auth())->assertJsonPath('policy_url', 'https://dashboard.example/privacy');

        $this->bootConsentConfigWith(['FRONTEND_URL' => 'https://dashboard.example', 'CONSENT_POLICY_URL' => 'https://legal.example/p']);
        $this->getJson('/api/v1/me/consent', $this->auth())->assertJsonPath('policy_url', 'https://legal.example/p');
    }

    public function test_explicit_env_values_are_used_as_given(): void
    {
        $this->bootConsentConfigWith(['CONSENT_VERSION' => '2027-02-01', 'CONSENT_POLICY_URL' => 'https://legal.example/p']);

        $this->getJson('/api/v1/me/consent', $this->auth())
            ->assertJsonPath('current_version', '2027-02-01')
            ->assertJsonPath('policy_url', 'https://legal.example/p');
    }

    // ---- what the admin and the nutritionist see ---------------------------

    public function test_the_admin_overview_says_which_consent_values_are_defaults(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $overview = fn () => $this->getJson('/api/v1/admin/overview', $this->auth($admin))->assertOk()->json('consent');

        $this->bootConsentConfigWith([]);
        $this->assertSame(['version' => '2026-10-01', 'version_set' => false, 'policy_url' => null, 'policy_url_set' => false], $overview());

        $this->bootConsentConfigWith(['FRONTEND_URL' => 'https://dashboard.example']);
        $this->assertSame(['version' => '2026-10-01', 'version_set' => false, 'policy_url' => 'https://dashboard.example/privacy', 'policy_url_set' => false], $overview());

        $this->bootConsentConfigWith(['CONSENT_VERSION' => '2026-10-01', 'CONSENT_POLICY_URL' => 'https://dashboard.example/privacy']);
        $this->assertSame(['version' => '2026-10-01', 'version_set' => true, 'policy_url' => 'https://dashboard.example/privacy', 'policy_url_set' => true], $overview());
    }

    public function test_the_nutritionist_sees_the_patients_consent_status_on_the_patient_only(): void
    {
        $token = $this->auth($this->nutritionist);
        $id = $this->subscriber->id;

        $this->getJson("/api/v1/clients/{$id}", $token)->assertOk()->assertJsonPath('consent', [
            'accepted_version' => null, 'accepted_at' => null, 'current_version' => self::VERSION, 'up_to_date' => false,
        ]);

        $this->accept();
        $shown = $this->getJson("/api/v1/clients/{$id}", $token)->assertOk();
        $this->assertSame(self::VERSION, $shown->json('consent.accepted_version'));
        $this->assertNotNull($shown->json('consent.accepted_at'));
        $this->assertTrue($shown->json('consent.up_to_date'));
        $this->assertArrayNotHasKey('ip_address', $shown->json('consent'));
        $this->assertArrayNotHasKey('user_agent', $shown->json('consent'));

        $this->assertArrayNotHasKey('consent', $this->getJson('/api/v1/clients', $token)->assertOk()->json('data.0'));

        config(['patient_app.consent.version' => '2027-02-01']);
        $this->getJson("/api/v1/clients/{$id}", $token)->assertJsonPath('consent.up_to_date', false)->assertJsonPath('consent.accepted_version', self::VERSION);
    }

    public function test_another_nutritionists_patient_stays_a_404(): void
    {
        $stranger = User::factory()->nutritionist()->create();

        $this->getJson("/api/v1/clients/{$this->subscriber->id}", $this->auth($stranger))->assertNotFound();
    }

    // ---- data lifecycle ----------------------------------------------------

    public function test_the_consent_record_goes_with_the_patients_account(): void
    {
        $this->accept();
        $this->assertSame(1, PatientConsent::count());

        $this->deleteJson("/api/v1/clients/{$this->subscriber->id}", [], $this->auth($this->nutritionist))->assertNoContent();

        $this->assertSame(0, PatientConsent::count());
    }

    public function test_the_migration_creates_and_drops_the_table(): void
    {
        $migration = $this->migrationFile('2026_09_29_130000_create_patient_consents_table');

        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('patient_consents'));
            $migration->up();
            $this->assertTrue(Schema::hasTable('patient_consents'));
            foreach (['user_id', 'version', 'accepted_at', 'ip_address', 'user_agent'] as $column) {
                $this->assertTrue(Schema::hasColumn('patient_consents', $column), $column);
            }
            // One acceptance per (patient, version).
            DB::table('patient_consents')->insert(['user_id' => $this->client->id, 'version' => 'v1', 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->expectException(QueryException::class);
            DB::table('patient_consents')->insert(['user_id' => $this->client->id, 'version' => 'v1', 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            if (! Schema::hasTable('patient_consents')) {
                $migration->up();
            }
        }
    }
}
