<?php

namespace Tests\Feature\Demo;

use App\Console\Commands\Demo\SeedDemoData;
use App\Models\AiUsageLog;
use App\Models\Appointment;
use App\Models\Food;
use App\Models\MealLog;
use App\Models\MealPlan;
use App\Models\NutritionistPost;
use App\Models\PatientCheckin;
use App\Models\PatientConsent;
use App\Models\PatientNotification;
use App\Models\PatientQuickRequest;
use App\Models\PatientWellbeingLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Consent\ConsentService;
use App\Services\Notifications\FcmPushService;
use App\Support\ClinicDay;
use Database\Seeders\ArabicFoodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** `demo:seed`: fake, flagged, relative-to-today demo accounts that touch nothing else. */
class DemoSeedTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ArabicFoodSeeder::class);
        config(['demo.nutritionist_password' => 'demo-nutritionist-pw', 'demo.patient_password' => 'demo-patient-pw']);
    }

    /** This test is about consent too: a patient token must not be given it automatically. */
    protected function acceptsConsentForPatients(): bool
    {
        return false;
    }

    private function seedDemo(array $options = []): void
    {
        $this->artisan('demo:seed', $options)->assertSuccessful();
    }

    private function demoNutritionist(): User
    {
        return User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->firstOrFail();
    }

    /** @return array<string, Subscriber> keyed a, b, c, d */
    private function patients(): array
    {
        $rows = Subscriber::withoutGlobalScopes()->where('nutritionist_id', $this->demoNutritionist()->id)->with('user')->orderBy('id')->get();

        return array_combine(['a', 'b', 'c', 'd'], $rows->all());
    }

    private function snapshot(): array
    {
        return [
            'users' => User::count(), 'subscribers' => Subscriber::withoutGlobalScopes()->count(), 'logs' => MealLog::count(),
            'plans' => MealPlan::count(), 'notifications' => PatientNotification::count(), 'posts' => NutritionistPost::count(),
            'appointments' => Appointment::count(), 'consents' => PatientConsent::count(),
        ];
    }

    // ---- what it builds ------------------------------------------------------------------------------------------------

    public function test_it_builds_the_demo_nutritionist_and_four_flagged_patients(): void
    {
        $this->seedDemo();

        $nutritionist = $this->demoNutritionist();
        $this->assertTrue($nutritionist->is_demo);
        $this->assertStringEndsWith('(تجريبي)', $nutritionist->name);
        $this->assertSame('PS', $nutritionist->country);
        $this->assertTrue($nutritionist->hasRole('nutritionist'));
        $this->assertTrue(Hash::check('demo-nutritionist-pw', $nutritionist->password));

        $patients = $this->patients();
        $this->assertCount(4, $patients);

        foreach ($patients as $subscriber) {
            $this->assertTrue($subscriber->user->is_demo, 'every demo user carries the marker');
            $this->assertStringStartsWith('demo.', $subscriber->user->username);
            $this->assertStringStartsWith('+1555555', $subscriber->user->phone, 'fictional phone range');
            $this->assertTrue($subscriber->user->hasRole('client'));
            $this->assertTrue(Hash::check('demo-patient-pw', $subscriber->user->password));
            $this->assertTrue($subscriber->isActive(), 'active follow-up');
            $this->assertNotNull(MealPlan::where('subscriber_id', $subscriber->id)->where('status', 'active')->first(), 'every patient has an active plan');
        }

        $this->assertSame(1, User::where('is_demo', true)->role('nutritionist')->count());
        $this->assertSame(5, User::where('is_demo', true)->count());
    }

    public function test_the_four_profiles_are_varied(): void
    {
        $this->seedDemo();
        $p = $this->patients();
        $bmi = fn (Subscriber $s) => \App\Support\Bmi::classify(\App\Support\Bmi::compute((float) $s->healthProfile->weight_kg, (float) $s->healthProfile->height_cm), (int) $s->healthProfile->age);

        $this->assertSame('overweight', $bmi($p['a']));
        $this->assertSame('obesity_1', $bmi($p['b']));
        $this->assertSame('underweight', $bmi($p['c']));

        $this->assertSame('female', $p['a']->healthProfile->gender);
        $this->assertSame('male', $p['b']->healthProfile->gender);
        $this->assertSame(['diabetes_type2'], $p['a']->conditions()->pluck('code')->all());
        $this->assertSame(['hypertension'], $p['b']->conditions()->pluck('code')->all());
        $this->assertSame(['tree_nuts'], $p['a']->allergyItems()->pluck('group')->all());
        $this->assertSame('weight_loss', $p['a']->patientGoal->goal_type);
        $this->assertSame('weight_loss', $p['b']->patientGoal->goal_type);
        $this->assertSame('weight_gain', $p['c']->patientGoal->goal_type);
    }

    public function test_patient_d_has_no_consent_and_the_others_have_accepted_the_current_version(): void
    {
        $this->seedDemo();
        $p = $this->patients();
        $consent = app(ConsentService::class);

        foreach (['a', 'b', 'c'] as $key) {
            $this->assertTrue($consent->hasAcceptedCurrent($p[$key]->user), "{$key} accepted");
        }

        $this->assertFalse($consent->hasAcceptedCurrent($p['d']->user), 'the store reviewer meets the consent screen first');
        $this->assertSame(0, PatientConsent::where('user_id', $p['d']->user_id)->count());

        // ... and that is what the API tells that patient.
        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($p['d']->user))->assertForbidden()->assertJsonPath('code', 'consent_required');
        $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($p['a']->user))->assertSuccessful();
    }

    public function test_the_rich_patients_have_six_weeks_of_weights_and_fourteen_days_of_logs_near_eighty_percent(): void
    {
        $this->seedDemo();
        $today = ClinicDay::today();
        $p = $this->patients();

        foreach (['a', 'b', 'c'] as $key) {
            $s = $p[$key];
            $weights = $s->bodyCompositionReadings()->reorder('recorded_at')->get();
            $this->assertGreaterThanOrEqual(10, $weights->count());
            $weeksBack = abs($today->diffInDays($weights->first()->recorded_at));
            $this->assertEqualsWithDelta(42, $weeksBack, 1.5, 'about six weeks back');
            $change = $weights->last()->weight_kg - $weights->first()->weight_kg;
            $this->assertTrue($key === 'c' ? $change > 0 : $change < 0, "{$key}: the weight moves toward the goal ({$change} kg)");
            $this->assertLessThanOrEqual($today->toDateString(), $weights->last()->recorded_at->toDateString());

            $logs = MealLog::where('subscriber_id', $s->id)->get();
            $plannedPerDay = MealPlan::where('subscriber_id', $s->id)->where('status', 'active')->first()->meals->flatMap->items->whereNull('parent_item_id')->count();
            $onPlan = $logs->whereNotNull('meal_item_id')->count();
            $rate = $onPlan / ($plannedPerDay * 14);
            $this->assertEqualsWithDelta(0.8, $rate, 0.12, "{$key}: adherence {$rate}");
            $this->assertGreaterThanOrEqual(2, $logs->whereNull('meal_item_id')->count(), 'a few off-plan meals');
        }

        $this->assertSame(0, MealLog::where('subscriber_id', $p['d']->id)->count(), 'the review patient: a plan, a few weights, the post');
        $this->assertSame(3, $p['d']->bodyCompositionReadings()->count());
    }

    public function test_all_dates_are_relative_to_today_and_nothing_is_in_the_future_but_the_appointments_and_schedule(): void
    {
        $this->seedDemo();
        $today = ClinicDay::today()->toDateString();

        $this->assertSame(0, MealLog::where('logged_at', '>', now())->count());
        $this->assertSame(0, \App\Models\BodyCompositionReading::where('recorded_at', '>', $today)->count());
        $this->assertSame(0, PatientWellbeingLog::where('log_date', '>', $today)->count());
        $this->assertGreaterThan(0, Appointment::where('starts_at', '>', now())->count());
        $this->assertSame(3, Appointment::where('starts_at', '>', now())->count(), 'one upcoming appointment for each of a, b and c');
        $this->assertSame(0, Appointment::where('starts_at', '>', now())->whereIn('subscriber_id', [$this->patients()['d']->id])->count());
    }

    public function test_the_rest_of_the_story_is_there_check_ins_quick_requests_wellbeing_schedule_and_one_post(): void
    {
        $this->seedDemo();
        $p = $this->patients();

        foreach (['a', 'b', 'c'] as $key) {
            $s = $p[$key];
            $this->assertSame(2, PatientCheckin::where('subscriber_id', $s->id)->where('status', 'submitted')->count(), "{$key}: two check-in answers");
            $this->assertSame(1, PatientQuickRequest::where('subscriber_id', $s->id)->count(), "{$key}: one quick request");
            $this->assertGreaterThanOrEqual(7, PatientWellbeingLog::where('subscriber_id', $s->id)->count());
            $this->assertNotNull($s->measurementSchedule, "{$key}: a measurement schedule");
        }

        $this->assertSame(0, PatientCheckin::where('subscriber_id', $p['d']->id)->count());
        $this->assertSame(1, NutritionistPost::count(), 'one post');
        $this->assertSame('published', NutritionistPost::first()->status);
        $this->assertSame('all', NutritionistPost::first()->audience);

        foreach ($p as $key => $s) {
            $this->assertSame(1, PatientNotification::where('user_id', $s->user_id)->where('type', 'education_post')->count(), "{$key} got the post in the inbox");
            $this->assertSame(1, app(\App\Services\Posts\PostService::class)->unreadCount($s), "{$key} sees it as unread");
        }
    }

    // ---- safety ---------------------------------------------------------------------------------------------------------------

    public function test_running_it_twice_creates_nothing_twice(): void
    {
        $this->seedDemo();
        $first = $this->snapshot();

        $this->artisan('demo:seed')->expectsOutputToContain('already exists')->assertSuccessful();

        $this->assertSame($first, $this->snapshot());
    }

    public function test_reset_rebuilds_only_the_demo_accounts_and_never_deletes_anyone_else(): void
    {
        $this->seedDemo();

        $nutritionist = User::factory()->nutritionist()->create(['name' => 'Real Nutritionist']);
        $client = User::factory()->client($nutritionist)->create();
        $realSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id]);
        $stranger = User::factory()->create();
        $oldDemoIds = User::where('is_demo', true)->pluck('id')->all();
        $realBefore = User::where('is_demo', false)->count();

        $this->seedDemo(['--reset' => true]);

        $this->assertSame($realBefore, User::where('is_demo', false)->count(), 'not one real user lost');
        foreach ([$nutritionist, $client, $stranger] as $user) {
            $this->assertNotNull(User::find($user->id));
        }
        $this->assertNotNull(Subscriber::withoutGlobalScopes()->find($realSubscriber->id));

        $this->assertSame(5, User::where('is_demo', true)->count());
        $this->assertEmpty(array_intersect($oldDemoIds, User::where('is_demo', true)->pluck('id')->all()), 'rebuilt, not kept');
        $this->assertSame(4, Subscriber::withoutGlobalScopes()->where('nutritionist_id', $this->demoNutritionist()->id)->count());
    }

    public function test_reset_refuses_when_a_demo_nutritionist_has_a_patient_without_the_marker_and_deletes_nothing(): void
    {
        $this->seedDemo();
        $demo = $this->demoNutritionist();
        $real = User::factory()->client($demo)->create();   // a real patient filed under the demo nutritionist
        Subscriber::factory()->active()->create(['nutritionist_id' => $demo->id, 'user_id' => $real->id]);
        $before = $this->snapshot();

        $this->artisan('demo:seed', ['--reset' => true])->expectsOutputToContain('Reset refused')->assertFailed();

        $this->assertSame($before, $this->snapshot());
        $this->assertNotNull(User::find($real->id));
        $this->assertNotNull(User::find($demo->id));
    }

    public function test_a_real_account_holding_the_demo_email_is_never_touched(): void
    {
        $real = User::factory()->nutritionist()->create(['email' => SeedDemoData::NUTRITIONIST_EMAIL, 'name' => 'Real']);

        foreach ([[], ['--reset' => true]] as $options) {
            $this->artisan('demo:seed', $options)->expectsOutputToContain('did not create')->assertFailed();
        }

        $this->assertSame('Real', $real->fresh()->name);
        $this->assertSame(0, User::where('is_demo', true)->count());
    }

    public function test_it_sends_no_notification_queues_no_job_and_calls_no_ai_or_push_provider(): void
    {
        Notification::fake();
        Queue::fake();
        Http::fake();
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'k']);
        $fcm = $this->spy(FcmPushService::class);

        $this->seedDemo();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $fcm->shouldNotHaveReceived('send');
        $this->assertSame(0, AiUsageLog::count(), 'no model call');
        $this->assertSame(0, PatientNotification::whereNotNull('pushed_at')->count());
    }

    public function test_a_demo_account_with_a_device_token_gets_a_real_push(): void
    {
        $this->seedDemo();
        $user = $this->patients()['a']->user;
        $this->assertNull($user->fcm_token, 'seeding gives no device token');

        // Someone signs in on a real phone: the account now has a token.
        $user->forceFill(['fcm_token' => 'tok-demo-phone'])->save();
        $this->fakePush();

        $row = app(\App\Services\Notifications\NotificationService::class)->notify($user, 'system', 'follow_up_resumed');

        $this->assertNull($row->fresh()->push_skipped);
        $this->assertNotNull($row->fresh()->pushed_at);
        $this->assertCount(1, $this->pushes);
        $this->assertSame('tok-demo-phone', $this->pushes[0]['token']);
    }

    public function test_a_demo_account_without_a_token_is_skipped_like_any_other(): void
    {
        $this->seedDemo();
        $this->fakePush();

        $row = app(\App\Services\Notifications\NotificationService::class)->notify($this->patients()['a']->user, 'system', 'follow_up_resumed');

        $this->assertSame('no_token', $row->fresh()->push_skipped);
        $this->assertSame([], $this->pushes);
    }

    // ---- the numbers an admin reads --------------------------------------------------------------------------------------------

    private function adminToken(): array
    {
        return $this->bearerFor(User::factory()->admin()->create());
    }

    public function test_the_admin_overview_leaves_demo_accounts_out(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => User::factory()->client($nutritionist)->create()->id]);
        $token = $this->adminToken();
        $before = $this->getJson('/api/v1/admin/overview', $token)->assertOk();

        $this->seedDemo();

        $after = $this->getJson('/api/v1/admin/overview', $token)->assertOk();
        $this->assertSame($before->json('nutritionists'), $after->json('nutritionists'));
        $this->assertSame($before->json('clients'), $after->json('clients'));
        $this->assertSame($before->json('active_clients'), $after->json('active_clients'));
        $this->assertSame(1, $after->json('active_clients'));
    }

    public function test_the_nutritionist_list_shows_the_demo_account_flagged_and_its_totals_exclude_it(): void
    {
        User::factory()->nutritionist()->create();
        $this->seedDemo();

        $response = $this->getJson('/api/v1/admin/nutritionists', $this->adminToken())->assertOk();

        $rows = collect($response->json('data'))->keyBy('email');
        $this->assertTrue($rows[SeedDemoData::NUTRITIONIST_EMAIL]['is_demo']);
        $this->assertSame([false], $rows->except(SeedDemoData::NUTRITIONIST_EMAIL)->pluck('is_demo')->unique()->values()->all());
        $this->assertSame(1, $response->json('meta.total'), 'real nutritionists only');
        $this->assertSame(1, $response->json('meta.demo_count'));
        $this->assertSame(4, $rows[SeedDemoData::NUTRITIONIST_EMAIL]['clients_count']);
    }

    public function test_the_ai_usage_report_leaves_demo_cost_and_patients_out(): void
    {
        $real = User::factory()->nutritionist()->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $real->id, 'user_id' => User::factory()->client($real)->create()->id]);
        $this->seedDemo();
        $demo = $this->demoNutritionist();

        $row = fn (User $n, int $in) => AiUsageLog::create(['feature' => 'ai_draft', 'nutritionist_id' => $n->id, 'model' => 'm', 'prompt_tokens' => $in, 'completion_tokens' => 0, 'total_tokens' => $in, 'success' => true, 'created_at' => now()]);
        $row($real, 1_000_000);    // 0.15 USD
        $row($demo, 9_000_000);    // would be 1.35 USD

        $report = $this->getJson('/api/v1/admin/ai-usage', $this->adminToken())->assertOk();

        $this->assertEquals(0.15, $report->json('totals.cost_usd'));
        $this->assertSame(1, $report->json('totals.calls'));
        $this->assertSame([$real->id], collect($report->json('top_nutritionists'))->pluck('id')->all());
        $this->assertSame(1, $report->json('active_patients'), 'the four demo patients do not count');
        $this->assertEquals(0.15, $report->json('cost_per_active_patient_usd'));
    }

    public function test_the_db_seeder_demo_account_is_flagged_too(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertTrue((bool) User::where('email', 'nutritionist@example.com')->value('is_demo'));
    }

    // ---- preconditions ------------------------------------------------------------------------------------------------------------

    public function test_missing_foods_or_roles_stop_it_cleanly(): void
    {
        Food::query()->delete();

        $this->artisan('demo:seed')->expectsOutputToContain('food database is empty')->assertFailed();
        $this->assertSame(0, User::where('is_demo', true)->count());
    }

    public function test_without_configured_passwords_random_ones_are_printed_once(): void
    {
        config(['demo.nutritionist_password' => null, 'demo.patient_password' => null]);

        $this->artisan('demo:seed')->expectsOutputToContain('shown only this once')->assertSuccessful();

        $this->assertFalse(Hash::check('', $this->demoNutritionist()->password));
        $this->assertSame(1, User::where('is_demo', true)->role('nutritionist')->count());
    }
}
