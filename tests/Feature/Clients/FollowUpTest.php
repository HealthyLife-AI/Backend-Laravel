<?php

namespace Tests\Feature\Clients;

use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AiSummaries\WeeklySummaryService;
use App\Services\Alerts\AlertEvaluationService;
use App\Services\Auth\RefreshTokenService;
use App\Services\Clients\ClientInviteService;
use App\Services\Notifications\FcmPushService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * End follow-up (archive) and resume: records kept, everything that acts
 * on the patient stops, and resuming restores it.
 */
class FollowUpTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    private Subscriber $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->patient->user->forceFill(['password' => Hash::make('patient-pass'), 'phone' => '+970590000001'])->save();
    }

    private function auth(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function archive(?Subscriber $subscriber = null): void
    {
        $this->postJson('/api/v1/clients/'.($subscriber ?? $this->patient)->id.'/archive', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('client.archived_at', fn ($v) => $v !== null);
    }

    private function resume(): TestResponse
    {
        return $this->postJson("/api/v1/clients/{$this->patient->id}/resume", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('client.archived_at', null);
    }

    // ---- roster -----------------------------------------------------------

    public function test_the_roster_hides_archived_patients_and_the_archived_filter_lists_them(): void
    {
        $this->archive();

        $roster = $this->getJson('/api/v1/clients', $this->auth())->assertOk();
        $this->assertSame([$this->other->id], collect($roster->json('data'))->pluck('id')->all());

        $archived = $this->getJson('/api/v1/clients?archived=1', $this->auth())->assertOk();
        $this->assertSame([$this->patient->id], collect($archived->json('data'))->pluck('id')->all());
    }

    public function test_archived_patients_are_left_out_of_dashboard_and_admin_counts(): void
    {
        DB::table('subscribers')->update(['adherence_status' => 'stable']);
        $this->archive();

        $this->getJson('/api/v1/dashboard/overview', $this->auth())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('active', 1)
            ->assertJsonPath('stable', 1)
            ->assertJsonPath('archived', 1);

        $admin = tap(User::factory()->create())->assignRole('admin');
        $this->getJson('/api/v1/admin/overview', $this->bearerFor($admin))
            ->assertOk()
            ->assertJsonPath('active_clients', 1);
    }

    // ---- scheduled jobs ---------------------------------------------------

    public function test_the_daily_alert_job_skips_archived_patients(): void
    {
        $this->archive();
        $seen = [];
        $this->mock(AlertEvaluationService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('evaluate')->andReturnUsing(function (Subscriber $s) use (&$seen) {
                $seen[] = $s->id;
            });
        });

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame([$this->other->id], $seen);
    }

    public function test_the_evening_reminder_job_skips_archived_patients(): void
    {
        DB::table('users')->whereIn('id', [$this->patient->user_id, $this->other->user_id])->update(['fcm_token' => 'device']);
        $this->archive();
        // Midday (after the API call, whose token uses the real clock), so the run never falls in the default quiet hours (22:00-07:00).
        $this->travelTo(now(config('scheduling.timezone'))->setTime(12, 0));
        $this->mock(FcmPushService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturnTrue();
            $mock->shouldReceive('send')->once();
        });

        $this->artisan('notifications:send-log-reminders')->assertSuccessful();
    }

    public function test_the_weekly_summary_job_skips_archived_patients(): void
    {
        $this->archive();
        $seen = [];
        $this->mock(WeeklySummaryService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('usesLlm')->andReturnFalse();
            $mock->shouldReceive('generateForWeek')->andReturnUsing(function (Subscriber $s) use (&$seen) {
                $seen[] = $s->id;
                throw new \RuntimeException('stop here');
            });
        });

        $this->artisan('ai-summaries:generate-weekly');

        $this->assertSame([$this->other->id], $seen);
    }

    public function test_saving_data_for_an_archived_patient_does_not_trigger_alert_evaluation(): void
    {
        config(['scheduling.self_trigger' => true]);
        $this->archive();
        $this->mock(AlertEvaluationService::class, fn ($mock) => $mock->shouldNotReceive('evaluate'));

        // Written directly (the API refuses it): the saved-hook must still skip.
        DB::table('subscribers')->where('id', $this->patient->id)->update(['last_logged_at' => now()]);
        $this->patient->healthProfile()->create(['weight_kg' => 80, 'height_cm' => 175, 'age' => 30, 'gender' => 'male', 'activity_level' => 'moderate']);
        $this->getJson('/api/v1/clients', $this->auth())->assertOk();
    }

    // ---- the patient's own app -------------------------------------------

    public function test_an_archived_patient_cannot_log_in(): void
    {
        $this->archive();

        $this->postJson('/api/v1/auth/login', ['phone' => '+970590000001', 'password' => 'patient-pass'])
            ->assertForbidden()
            ->assertJsonPath('code', 'follow_up_ended');

        // A wrong password still gets the generic answer: nothing leaks.
        $this->postJson('/api/v1/auth/login', ['phone' => '+970590000001', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('code');
    }

    public function test_an_archived_patient_cannot_log_a_meal_even_with_an_old_access_token(): void
    {
        $food = Food::factory()->create();
        $token = $this->bearerFor($this->patient->user);
        $this->archive();

        $this->postJson('/api/v1/me/meal-logs', ['food_id' => $food->id, 'quantity_grams' => 100], $token)
            ->assertForbidden()
            ->assertJsonPath('code', 'follow_up_ended');
        $this->getJson('/api/v1/me/meal-plan', $token)->assertForbidden();
        $this->assertDatabaseCount('meal_logs', 0);
    }

    public function test_archiving_revokes_refresh_tokens_and_invalidates_pending_invites(): void
    {
        $pending = Subscriber::factory()->forNutritionist($this->nutritionist)->create();
        $invite = app(ClientInviteService::class)->issue($pending);
        $refresh = app(RefreshTokenService::class)->issue($this->patient->user, Request::create('/'));

        $this->archive();
        $this->archive($pending);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh['plain']])->assertUnauthorized();
        $this->assertSame(0, DB::table('refresh_tokens')->where('user_id', $this->patient->user_id)->whereNull('revoked_at')->count());
        $this->postJson("/api/v1/invites/{$invite['plain']}/activate", ['password' => 'NewPass-123', 'password_confirmation' => 'NewPass-123'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This invite link has expired.');
    }

    // ---- read-only records -----------------------------------------------

    public function test_records_stay_readable_but_no_new_plans_edits_or_measurements(): void
    {
        $this->patient->healthProfile()->create(['weight_kg' => 80, 'height_cm' => 175, 'age' => 30, 'gender' => 'male', 'activity_level' => 'moderate']);
        $food = Food::factory()->create();
        $this->archive();
        $id = $this->patient->id;
        $auth = $this->auth();

        $this->getJson("/api/v1/clients/{$id}", $auth)->assertOk();
        $this->getJson("/api/v1/clients/{$id}/health-profile", $auth)->assertOk();
        $this->getJson("/api/v1/clients/{$id}/body-composition-readings", $auth)->assertOk();
        $this->getJson("/api/v1/clients/{$id}/meal-plans", $auth)->assertOk();
        $this->getJson("/api/v1/clients/{$id}/progress", $auth)->assertOk();
        $this->getJson("/api/v1/clients/{$id}/ai-summaries", $auth)->assertOk();

        $refused = [
            $this->putJson("/api/v1/clients/{$id}/health-profile", ['weight_kg' => 79, 'height_cm' => 175, 'age' => 30, 'gender' => 'male', 'activity_level' => 'moderate'], $auth),
            $this->postJson("/api/v1/clients/{$id}/body-composition-readings", ['recorded_at' => now()->toDateString(), 'weight_kg' => 79], $auth),
            $this->postJson("/api/v1/clients/{$id}/meal-plans", ['meals' => [['name' => 'lunch', 'items' => [['food_id' => $food->id, 'quantity_grams' => 100]]]]], $auth),
            $this->postJson("/api/v1/clients/{$id}/meal-plans/ai-draft", [], $auth),
        ];
        foreach ($refused as $response) {
            $response->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        }

        $this->assertEquals(80, $this->patient->healthProfile()->value('weight_kg'));
        $this->assertDatabaseCount('body_composition_readings', 0);
        $this->assertDatabaseCount('meal_plans', 0);
    }

    // ---- resume -----------------------------------------------------------

    public function test_resuming_follow_up_restores_everything(): void
    {
        $this->archive();
        $this->resume()->assertJsonPath('invite_token', null);

        $this->postJson('/api/v1/auth/login', ['phone' => '+970590000001', 'password' => 'patient-pass'])->assertOk();
        $this->assertContains($this->patient->id, collect($this->getJson('/api/v1/clients', $this->auth())->json('data'))->pluck('id'));
        $this->postJson("/api/v1/clients/{$this->patient->id}/body-composition-readings", ['recorded_at' => now()->toDateString(), 'weight_kg' => 79], $this->auth())
            ->assertCreated();
        $this->postJson('/api/v1/me/meal-logs', ['food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'meal_type' => 'lunch'], $this->bearerFor($this->patient->user))
            ->assertCreated();

        $seen = [];
        $this->mock(AlertEvaluationService::class, function ($mock) use (&$seen) {
            $mock->shouldReceive('evaluate')->andReturnUsing(function (Subscriber $s) use (&$seen) {
                $seen[] = $s->id;
            });
        });
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$this->patient->id, $this->other->id], $seen);
    }

    public function test_resuming_a_never_activated_patient_issues_a_new_invite(): void
    {
        $pending = Subscriber::factory()->forNutritionist($this->nutritionist)->create();
        app(ClientInviteService::class)->issue($pending);
        $this->archive($pending);

        $response = $this->postJson("/api/v1/clients/{$pending->id}/resume", [], $this->auth())->assertOk();
        $token = $response->json('invite_token');

        $this->assertNotNull($token);
        $this->postJson("/api/v1/invites/{$token}/activate", ['password' => 'NewPass-123', 'password_confirmation' => 'NewPass-123'])
            ->assertSuccessful();
    }

    // ---- ownership and delete --------------------------------------------

    public function test_another_nutritionist_cannot_archive_or_resume_my_patient(): void
    {
        $stranger = $this->bearerFor(User::factory()->nutritionist()->create());

        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $stranger)->assertNotFound();
        $this->assertNull($this->patient->fresh()->archived_at);

        $this->archive();
        $this->postJson("/api/v1/clients/{$this->patient->id}/resume", [], $stranger)->assertNotFound();
        $this->assertNotNull($this->patient->fresh()->archived_at);

        // Their write attempts get the usual 404, never the archived 409.
        $this->postJson("/api/v1/clients/{$this->patient->id}/body-composition-readings", ['recorded_at' => now()->toDateString(), 'weight_kg' => 79], $stranger)
            ->assertNotFound();
    }

    public function test_an_archived_patient_can_still_be_deleted_permanently(): void
    {
        $this->archive();

        $this->deleteJson("/api/v1/clients/{$this->patient->id}", [], $this->auth())->assertNoContent();

        $this->assertModelMissing($this->patient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
