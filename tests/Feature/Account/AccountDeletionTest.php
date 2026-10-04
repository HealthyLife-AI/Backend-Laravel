<?php

namespace Tests\Feature\Account;

use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\ClientInvite;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\PatientConsent;
use App\Models\PatientDeletionNotice;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-18: a patient deletes their own account from the app. Same deletion as
 * the nutritionist's, password required, rate-limited, and the nutritionist
 * gets a notice with only the patient's code and the date.
 */
class AccountDeletionTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    private const PASSWORD = 'patient-pass-1';

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        [$this->client, $this->subscriber] = $this->makePatient('+970590000001');
    }

    /** @return array{0: User, 1: Subscriber} */
    private function makePatient(string $phone): array
    {
        $client = User::factory()->create([
            'nutritionist_id' => $this->nutritionist->id,
            'phone' => $phone,
            'password' => Hash::make(self::PASSWORD),
            'email' => "patient{$phone}@example.test",
        ]);
        $client->assignRole('client');

        return [$client, Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id])];
    }

    private function auth(?User $as = null): array
    {
        return $this->bearerFor($as ?? $this->client);
    }

    private function deleteAccount(?string $password = self::PASSWORD, ?User $as = null)
    {
        return $this->deleteJson('/api/v1/me/account', $password === null ? [] : ['password' => $password], $this->auth($as));
    }

    /** Fills every kind of record a patient can have. */
    private function fillPatientData(Subscriber $subscriber): void
    {
        $user = $subscriber->user;
        $food = Food::factory()->create();

        HealthProfile::create(['subscriber_id' => $subscriber->id, 'weight_kg' => 80, 'height_cm' => 175, 'age' => 30, 'gender' => 'male', 'activity_level' => 'moderate']);
        $subscriber->bodyCompositionReadings()->create(['recorded_at' => now()->toDateString(), 'source' => 'self-reported', 'weight_kg' => 80]);

        $planId = DB::table('meal_plans')->insertGetId(['subscriber_id' => $subscriber->id, 'created_by' => $subscriber->nutritionist_id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $item = MealItem::create(['meal_id' => $mealId, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        // Phase 2: follow-up review + task, a notification and the preferences.
        $reviewId = DB::table('follow_up_reviews')->insertGetId(['subscriber_id' => $subscriber->id, 'nutritionist_id' => $subscriber->nutritionist_id, 'note' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('follow_up_tasks')->insert(['review_id' => $reviewId, 'subscriber_id' => $subscriber->id, 'title' => 'walk', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('patient_notifications')->insert(['user_id' => $user->id, 'category' => 'plan', 'type' => 'plan_activated', 'title' => 't', 'body' => 'b', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        MealItem::create(['meal_id' => $mealId, 'food_id' => $food->id, 'parent_item_id' => $item->id, 'quantity_grams' => 90, 'sort_order' => 1]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'meal_item_id' => $item->id, 'meal_type' => 'lunch', 'quantity_grams' => 100, 'logged_at' => now()]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $food->id, 'meal_type' => 'snack', 'quantity_grams' => 50, 'logged_at' => now()]);

        Alert::create(['subscriber_id' => $subscriber->id, 'type' => Alert::TYPE_NO_LOG, 'message' => 'x', 'is_read' => false]);
        AiSummary::create(['subscriber_id' => $subscriber->id, 'week_start' => now()->startOfWeek()->toDateString(), 'summary_text' => 'summary', 'is_fallback' => false, 'generated_at' => now()]);
        ClientInvite::create(['subscriber_id' => $subscriber->id, 'token_hash' => hash('sha256', 'token-'.$subscriber->id), 'expires_at' => now()->addDays(3)]);
        PatientConsent::create(['user_id' => $user->id, 'version' => 'v1', 'accepted_at' => now(), 'ip_address' => '127.0.0.1', 'user_agent' => 'test']);

        $user->forceFill(['fcm_token' => 'fcm-token-'.$user->id])->save();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hash', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sess'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        // A refresh token, the way a real login makes one.
        $this->postJson('/api/v1/auth/login', ['phone' => $user->phone, 'password' => self::PASSWORD])->assertOk();
    }

    // ---- happy path -------------------------------------------------------

    public function test_a_patient_deletes_their_account_with_their_password(): void
    {
        $this->fillPatientData($this->subscriber);
        $token = $this->auth();

        $this->deleteAccount()->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $this->client->id]);
        $this->assertDatabaseMissing('subscribers', ['id' => $this->subscriber->id]);
        // The token in hand is dead the moment the account is.
        $this->getJson('/api/v1/auth/me', $token)->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['phone' => '+970590000001', 'password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_deleting_the_account_ends_every_session_refresh_tokens_included(): void
    {
        $session = $this->postJson('/api/v1/auth/login', ['phone' => $this->client->phone, 'password' => self::PASSWORD])->assertOk()->json();

        $this->deleteJson('/api/v1/me/account', ['password' => self::PASSWORD], ['Authorization' => "Bearer {$session['access_token']}"])->assertNoContent();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$session['access_token']}"])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonMissingPath('access_token');
    }

    public function test_the_nutritionist_is_told_with_only_the_code_and_the_date(): void
    {
        $code = $this->subscriber->code;
        $this->deleteAccount()->assertNoContent();

        $notice = PatientDeletionNotice::firstOrFail();
        $this->assertSame($this->nutritionist->id, $notice->nutritionist_id);
        $this->assertSame($code, $notice->patient_code);
        $this->assertTrue($notice->deleted_at->isToday());

        // The row holds nothing else that could identify the patient.
        $this->assertEqualsCanonicalizing(
            ['id', 'nutritionist_id', 'patient_code', 'deleted_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('patient_deletion_notices'),
        );
        $this->assertStringNotContainsString($this->client->name, json_encode(DB::table('patient_deletion_notices')->get()));
        $this->assertStringNotContainsString('+970590000001', json_encode(DB::table('patient_deletion_notices')->get()));
    }

    public function test_only_a_patient_initiated_deletion_leaves_a_notice(): void
    {
        $this->deleteJson("/api/v1/clients/{$this->subscriber->id}", [], $this->auth($this->nutritionist))->assertNoContent();

        $this->assertSame(0, PatientDeletionNotice::count());
    }

    public function test_the_deleted_code_is_never_handed_out_again(): void
    {
        $code = $this->subscriber->code;
        $this->deleteAccount()->assertNoContent();

        $new = $this->postJson('/api/v1/clients', ['name' => 'New', 'phone' => '+970590000099', 'goal' => 'weight_loss'], $this->auth($this->nutritionist))->assertCreated();

        $this->assertNotSame($code, $new->json('client.code'));
    }

    // ---- everything really goes -------------------------------------------

    public function test_every_record_about_the_patient_is_gone_table_by_table(): void
    {
        $this->fillPatientData($this->subscriber);
        [$otherClient, $otherSubscriber] = $this->makePatient('+970590000002');
        $this->fillPatientData($otherSubscriber);

        $user = $this->client;
        $subscriberId = $this->subscriber->id;
        $userId = $user->id;
        $email = $user->email;
        $planIds = DB::table('meal_plans')->where('subscriber_id', $subscriberId)->pluck('id');

        // Sanity: the data really is there before.
        foreach (['health_profiles', 'body_composition_readings', 'meal_plans', 'meal_logs', 'alerts', 'ai_summaries', 'client_invites', 'follow_up_reviews', 'follow_up_tasks'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('subscriber_id', $subscriberId)->count(), "{$table} was seeded");
        }
        $this->assertGreaterThan(0, DB::table('refresh_tokens')->where('user_id', $userId)->count());

        $this->deleteAccount()->assertNoContent();

        // Every table that can point at a user or a subscriber, found from the schema
        // itself, so a table added later is caught rather than silently skipped.
        $covered = ['subscribers', 'health_profiles', 'body_composition_readings', 'meal_plans', 'meal_logs', 'alerts', 'ai_summaries', 'client_invites',
            'patient_consents', 'refresh_tokens', 'sessions', 'password_reset_tokens', 'model_has_roles', 'model_has_permissions', 'users',
            'meals', 'meal_items', 'nutritionist_profiles', 'foods', 'patient_deletion_notices', 'permissions', 'roles', 'role_has_permissions',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
            'follow_up_reviews', 'follow_up_tasks', 'patient_notifications', 'notification_preferences'];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            $columns = Schema::getColumnListing($name);
            if (array_intersect($columns, ['user_id', 'subscriber_id', 'model_id', 'email'])) {
                $this->assertContains($name, $covered, "table `{$name}` references users/subscribers: add it to the account-deletion cascade and to this test");
            }
        }

        $this->assertSame(0, DB::table('subscribers')->where('id', $subscriberId)->count());
        foreach (['health_profiles', 'body_composition_readings', 'meal_plans', 'meal_logs', 'alerts', 'ai_summaries', 'client_invites', 'follow_up_reviews', 'follow_up_tasks'] as $table) {
            $this->assertSame(0, DB::table($table)->where('subscriber_id', $subscriberId)->count(), "{$table} still has the patient's rows");
        }
        $this->assertSame(0, DB::table('meals')->whereIn('meal_plan_id', $planIds)->count());
        $this->assertSame(0, DB::table('meal_items')->whereIn('meal_id', DB::table('meals')->whereIn('meal_plan_id', $planIds)->pluck('id'))->count());
        $this->assertSame(0, DB::table('patient_consents')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('patient_notifications')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('notification_preferences')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('refresh_tokens')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $userId)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $email)->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $userId)->where('model_type', User::class)->count());
        $this->assertSame(0, DB::table('users')->where('id', $userId)->count());
        $this->assertSame(0, DB::table('users')->where('phone', '+970590000001')->count());

        // ...and the other patient's, and the nutritionist's, are untouched.
        $this->assertSame(1, DB::table('subscribers')->where('id', $otherSubscriber->id)->count());
        foreach (['health_profiles', 'body_composition_readings', 'meal_plans', 'meal_logs', 'alerts', 'ai_summaries', 'client_invites', 'follow_up_reviews', 'follow_up_tasks'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->where('subscriber_id', $otherSubscriber->id)->count(), "{$table} lost the OTHER patient's rows");
        }
        $this->assertSame(1, DB::table('patient_consents')->where('user_id', $otherClient->id)->count());
        $this->assertSame(1, DB::table('users')->where('id', $this->nutritionist->id)->count());
    }

    // ---- the password -----------------------------------------------------

    public function test_a_wrong_password_deletes_nothing_and_says_so_on_the_password_field(): void
    {
        $this->deleteAccount('not-my-password')->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $this->client->id]);
        $this->assertDatabaseHas('subscribers', ['id' => $this->subscriber->id]);
        $this->assertSame(0, PatientDeletionNotice::count());
    }

    public function test_a_missing_password_is_refused(): void
    {
        $this->deleteAccount(null)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->deleteJson('/api/v1/me/account', ['password' => ''], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $this->client->id]);
    }

    public function test_it_is_rate_limited_per_patient(): void
    {
        config(['patient_app.account_deletion.attempts_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->deleteAccount('wrong')->assertUnprocessable();
        }
        // The fourth attempt is refused before the password is even looked at —
        // even with the right one.
        $this->deleteAccount()->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $this->client->id]);
    }

    // ---- who may call it ---------------------------------------------------

    public function test_a_nutritionist_cannot_use_it(): void
    {
        $this->nutritionist->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        $this->deleteAccount(self::PASSWORD, $this->nutritionist)->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $this->nutritionist->id]);
        $this->deleteJson('/api/v1/me/account', ['password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_an_archived_patient_gets_follow_up_ended_and_keeps_their_account(): void
    {
        $token = $this->auth();
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->deleteJson('/api/v1/me/account', ['password' => self::PASSWORD], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');

        $this->assertDatabaseHas('users', ['id' => $this->client->id]);
        $this->assertSame(0, PatientDeletionNotice::count());
    }

    public function test_it_works_even_before_the_patient_has_accepted_the_privacy_policy(): void
    {
        config(['patient_app.consent.version' => '2026-10-01']);

        $this->deleteAccount()->assertNoContent();
    }

    public function test_a_patient_can_only_delete_their_own_account(): void
    {
        [, $other] = $this->makePatient('+970590000002');

        $this->deleteAccount()->assertNoContent();

        $this->assertDatabaseHas('subscribers', ['id' => $other->id]);
    }

    // ---- the notices ------------------------------------------------------

    public function test_the_nutritionist_lists_their_notices_newest_first_and_dismisses_them(): void
    {
        $token = $this->auth($this->nutritionist);
        PatientDeletionNotice::create(['nutritionist_id' => $this->nutritionist->id, 'patient_code' => 'PT-101', 'deleted_at' => now()->subDays(3)]);
        $newer = PatientDeletionNotice::create(['nutritionist_id' => $this->nutritionist->id, 'patient_code' => 'PT-102', 'deleted_at' => now()->subDay()]);

        $list = $this->getJson('/api/v1/notices', $token)->assertOk()->json();

        $this->assertSame(['PT-102', 'PT-101'], array_column($list, 'patient_code'));
        $this->assertSame(['id', 'patient_code', 'deleted_at'], array_keys($list[0]), 'only the code and the date, plus the id to dismiss it by');

        $this->deleteJson("/api/v1/notices/{$newer->id}", [], $token)->assertNoContent();
        $this->assertSame(['PT-101'], array_column($this->getJson('/api/v1/notices', $token)->json(), 'patient_code'));
        $this->deleteJson("/api/v1/notices/{$newer->id}", [], $token)->assertNotFound();
    }

    public function test_a_nutritionist_only_sees_and_dismisses_their_own_notices(): void
    {
        $stranger = User::factory()->nutritionist()->create();
        $theirs = PatientDeletionNotice::create(['nutritionist_id' => $stranger->id, 'patient_code' => 'PT-500', 'deleted_at' => now()]);

        $this->getJson('/api/v1/notices', $this->auth($this->nutritionist))->assertOk()->assertExactJson([]);
        $this->deleteJson("/api/v1/notices/{$theirs->id}", [], $this->auth($this->nutritionist))->assertNotFound();
        $this->assertNotNull($theirs->fresh());
    }

    public function test_a_patient_and_the_unauthenticated_cannot_read_notices(): void
    {
        $this->getJson('/api/v1/notices', $this->auth())->assertForbidden();
        $this->deleteJson('/api/v1/notices/1', [], $this->auth())->assertForbidden();
        $this->getJson('/api/v1/notices')->assertUnauthorized();
    }

    public function test_a_notice_reaches_the_right_nutritionist_end_to_end(): void
    {
        $code = $this->subscriber->code;
        $this->deleteAccount()->assertNoContent();

        $list = $this->getJson('/api/v1/notices', $this->auth($this->nutritionist))->assertOk()->json();

        $this->assertSame([$code], array_column($list, 'patient_code'));
    }

    // ---- the migration -----------------------------------------------------

    public function test_the_migration_creates_and_drops_the_notices_table(): void
    {
        $migration = $this->migrationFile('2026_09_29_140000_create_patient_deletion_notices_table');

        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('patient_deletion_notices'));
            $migration->up();
            $this->assertTrue(Schema::hasTable('patient_deletion_notices'));
            $this->assertTrue(Schema::hasColumns('patient_deletion_notices', ['nutritionist_id', 'patient_code', 'deleted_at']));
        } finally {
            if (! Schema::hasTable('patient_deletion_notices')) {
                $migration->up();
            }
        }
    }
}
