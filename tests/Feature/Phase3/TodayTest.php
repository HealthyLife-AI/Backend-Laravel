<?php

namespace Tests\Feature\Phase3;

use App\Models\Appointment;
use App\Models\Meal;
use App\Models\MealPlan;
use App\Models\NutritionistPost;
use App\Models\PatientCheckin;
use App\Models\PatientNotification;
use App\Models\PatientQuickRequest;
use App\Models\PlanActivity;
use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Models\User;
use App\Services\Scheduling\SelfScheduler;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/**
 * Phase 3, B: the morning message. Deterministic, built from existing data
 * only, with no calorie number anywhere; and the 07:30 push, once a day.
 * 2026-10-26 is a Monday (day_index 0).
 */
class TodayTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $strangerToken;

    private array $clientToken;

    private Subscriber $otherSubscriber;

    private array $otherClientToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'patient-device']);
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());
        $this->clientToken = $this->bearerFor($this->client);

        // A second patient, with a token issued before time is moved (the JWT check reads the real clock).
        $otherUser = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherUser->id]);
        $this->otherClientToken = $this->bearerFor($otherUser);

        // Monday 10:00 clinic time.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    private function plan(string $status = 'active', ?string $startDate = null, ?Subscriber $s = null): MealPlan
    {
        return MealPlan::create([
            'subscriber_id' => ($s ?? $this->subscriber)->id, 'created_by' => $this->nutritionist->id, 'name' => 'خطة', 'status' => $status,
            'start_date' => $startDate, 'activated_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function meal(MealPlan $plan, string $name, ?int $dayIndex = null, int $order = 0): Meal
    {
        return Meal::create(['meal_plan_id' => $plan->id, 'name' => $name, 'day_index' => $dayIndex, 'sort_order' => $order]);
    }

    private function today(?array $token = null)
    {
        return $this->getJson('/api/v1/me/today', $token ?? $this->clientToken);
    }

    private function types(): array
    {
        return array_column($this->today()->assertOk()->json('reminders'), 'type');
    }

    // ---- content ----------------------------------------------------------------------------------

    public function test_without_a_plan_the_meals_are_empty_and_the_rest_still_shows(): void
    {
        $this->today()->assertOk()
            ->assertJsonPath('date', '2026-10-26')->assertJsonPath('meals', [])->assertJsonPath('activities', [])->assertJsonPath('reminders', [])
            ->assertJsonStructure(['date', 'meals', 'activities', 'reminders', 'motivation_ar']);

        SubscriberMeasurementSchedule::create(['subscriber_id' => $this->subscriber->id, 'interval_days' => 14, 'next_due_on' => '2026-10-26']);
        $this->assertSame(['measurement'], $this->types());
    }

    public function test_with_a_plan_it_lists_the_meals_and_activities_without_any_calorie_number(): void
    {
        $plan = $this->plan();
        $this->meal($plan, 'lunch', null, 2);
        $this->meal($plan, 'breakfast', null, 1);
        $this->meal($plan, 'dinner', null, 3);
        PlanActivity::create(['meal_plan_id' => $plan->id, 'title' => 'مشي خفيف', 'duration_minutes' => 30, 'times_per_day' => 2, 'sort_order' => 0]);

        $response = $this->today()->assertOk();

        $this->assertSame([
            ['name' => 'breakfast', 'name_ar' => 'الفطور', 'window' => 'الصبح'],
            ['name' => 'lunch', 'name_ar' => 'الغداء', 'window' => 'الظهر'],
            ['name' => 'dinner', 'name_ar' => 'العشاء', 'window' => 'المساء'],
        ], $response->json('meals'), 'in the plan\'s own order');
        $this->assertSame([['title' => 'مشي خفيف', 'duration_minutes' => 30, 'times_per_day' => 2]], $response->json('activities'));

        $body = strtolower($response->getContent());
        foreach (['calori', 'kcal', 'protein', 'carb', 'bmr', 'weight'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "must not carry {$leak}");
        }
    }

    public function test_a_weekly_plan_shows_only_todays_meals(): void
    {
        $plan = $this->plan();
        $this->meal($plan, 'breakfast', 0);   // Monday
        $this->meal($plan, 'lunch', 1);       // Tuesday
        $this->meal($plan, 'dinner', 0);

        $this->assertSame(['breakfast', 'dinner'], array_column($this->today()->json('meals'), 'name'));

        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', config('scheduling.timezone')));
        $this->assertSame(['lunch'], array_column($this->today()->json('meals'), 'name'));
    }

    public function test_a_draft_a_future_plan_and_an_archived_plan_show_nothing(): void
    {
        $draft = $this->plan('draft');
        $this->meal($draft, 'breakfast');
        $future = $this->plan('active', '2026-10-28');
        $this->meal($future, 'lunch');

        $this->assertSame([], $this->today()->json('meals'));

        $future->forceFill(['start_date' => '2026-10-26'])->save();
        $this->assertSame(['lunch'], array_column($this->today()->json('meals'), 'name'));
    }

    // ---- reminders ----------------------------------------------------------------------------------------

    public function test_the_measurement_reminder_follows_the_due_date(): void
    {
        $schedule = SubscriberMeasurementSchedule::create(['subscriber_id' => $this->subscriber->id, 'interval_days' => 14, 'next_due_on' => '2026-10-27']);
        $this->assertSame([], $this->types(), 'not yet due');

        $schedule->forceFill(['next_due_on' => '2026-10-26'])->save();
        $this->assertSame('اليوم موعد قياساتك الجسدية.', $this->today()->json('reminders.0.text_ar'));

        $schedule->forceFill(['next_due_on' => '2026-10-20'])->save();
        $this->assertSame('قياساتك الجسدية متأخرة، سجّلها لما تقدر.', $this->today()->json('reminders.0.text_ar'));
    }

    public function test_a_pending_checkin_is_a_reminder_until_it_is_answered(): void
    {
        $checkin = PatientCheckin::create(['subscriber_id' => $this->subscriber->id, 'due_on' => '2026-10-26', 'status' => 'pending']);
        $this->assertSame(['checkin'], $this->types());

        $checkin->forceFill(['status' => 'submitted'])->save();
        $this->assertSame([], $this->types());
    }

    public function test_an_unread_answer_to_a_quick_request_is_a_reminder_until_the_list_is_opened(): void
    {
        $open = PatientQuickRequest::create(['subscriber_id' => $this->subscriber->id, 'reason' => 'craving', 'status' => 'open']);
        $this->assertSame([], $this->types(), 'unanswered: nothing to read yet');

        $open->forceFill(['status' => 'answered', 'response' => 'تمام', 'responded_at' => now()])->save();
        $this->assertSame(['quick_request'], $this->types());

        $this->getJson('/api/v1/me/quick-requests', $this->clientToken)->assertOk()->assertJsonPath('0.response', 'تمام');
        $this->assertSame([], $this->types(), 'she has read it');
        $this->assertNotNull($open->fresh()->response_seen_at);
    }

    public function test_an_appointment_today_shows_its_clinic_time(): void
    {
        $make = function (string $at, string $status = 'booked'): Appointment {
            $appointment = Appointment::create([
                'subscriber_id' => $this->subscriber->id, 'nutritionist_id' => $this->nutritionist->id, 'type' => 'follow_up', 'channel' => 'phone',
                'starts_at' => CarbonImmutable::parse($at, config('scheduling.timezone'))->utc(), 'ends_at' => CarbonImmutable::parse($at, config('scheduling.timezone'))->addMinutes(30)->utc(),
            ]);

            return $appointment->forceFill(['status' => $status]);
        };
        $save = fn (Appointment $a) => $a->save();

        $save($make('2026-10-27 12:30'));                // tomorrow
        $save($make('2026-10-26 18:00', 'cancelled'));   // today but cancelled
        $this->assertSame([], $this->types());

        $save($make('2026-10-26 12:30'));
        $reminders = $this->today()->json('reminders');
        $this->assertSame([['type' => 'appointment', 'text_ar' => 'عندك موعد مع أخصائيتك اليوم الساعة 12:30.']], $reminders);
    }

    public function test_unread_posts_are_counted_in_words(): void
    {
        $post = fn () => NutritionistPost::create(['author_id' => $this->nutritionist->id, 'title' => 't', 'body' => 'b', 'audience' => 'all', 'status' => 'published', 'published_at' => now()]);

        $first = $post();
        $this->assertSame('عندك نصيحة جديدة من أخصائيتك.', $this->today()->json('reminders.0.text_ar'));

        $post();
        $this->assertSame('عندك نصيحتان جديدتان من أخصائيتك.', $this->today()->json('reminders.0.text_ar'));

        $post();
        $this->assertSame('عندك 3 نصائح جديدة من أخصائيتك.', $this->today()->json('reminders.0.text_ar'));

        $this->postJson("/api/v1/me/posts/{$first->id}/read", [], $this->clientToken)->assertOk();
        $this->assertSame('عندك نصيحتان جديدتان من أخصائيتك.', $this->today()->json('reminders.0.text_ar'));
    }

    public function test_every_reminder_type_together_in_a_fixed_order(): void
    {
        SubscriberMeasurementSchedule::create(['subscriber_id' => $this->subscriber->id, 'interval_days' => 14, 'next_due_on' => '2026-10-26']);
        PatientCheckin::create(['subscriber_id' => $this->subscriber->id, 'due_on' => '2026-10-26', 'status' => 'pending']);
        PatientQuickRequest::create(['subscriber_id' => $this->subscriber->id, 'reason' => 'craving', 'status' => 'answered', 'response' => 'x']);
        Appointment::create(['subscriber_id' => $this->subscriber->id, 'nutritionist_id' => $this->nutritionist->id, 'type' => 'follow_up', 'channel' => 'phone', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        NutritionistPost::create(['author_id' => $this->nutritionist->id, 'title' => 't', 'body' => 'b', 'audience' => 'all', 'status' => 'published', 'published_at' => now()]);

        $this->assertSame(['measurement', 'checkin', 'quick_request', 'appointment', 'posts'], $this->types());
    }

    // ---- motivation ----------------------------------------------------------------------------------------------

    public function test_the_motivation_line_is_stable_within_a_day_and_moves_with_the_day_and_the_patient(): void
    {
        $lines = config('motivation.lines');
        $this->assertGreaterThanOrEqual(10, count($lines));

        $first = $this->today()->json('motivation_ar');
        $this->assertContains($first, $lines);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 22:30', config('scheduling.timezone')));
        $this->assertSame($first, $this->today()->json('motivation_ar'), 'same all day');

        $expected = fn (Subscriber $s, string $day) => $lines[($s->id + CarbonImmutable::parse($day)->dayOfYear) % count($lines)];
        $this->assertSame($expected($this->subscriber, '2026-10-26'), $first);

        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', config('scheduling.timezone')));
        $this->assertSame($expected($this->subscriber, '2026-10-27'), $this->today()->json('motivation_ar'));
        $this->assertNotSame($first, $this->today()->json('motivation_ar'), 'the next day, the next line');

        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => User::factory()->client($this->nutritionist)->create()->id]);
        $this->assertNotSame($expected($this->subscriber, '2026-10-27'), $expected($other, '2026-10-27'), 'neighbouring patients differ');
    }

    public function test_the_motivation_lines_are_neutral(): void
    {
        foreach (config('motivation.lines') as $line) {
            $this->assertDoesNotMatchRegularExpression('/\d/u', $line, 'no number, so no promise of one');
            foreach (['كيلو', 'كغ', 'وزن', 'نزّل', 'دواء', 'مرض', 'سعر'] as $word) {
                $this->assertStringNotContainsString($word, $line, 'no weight promise or medical claim');
            }
        }
    }

    public function test_without_any_motivation_line_the_field_is_omitted(): void
    {
        config(['motivation.lines' => []]);

        $this->assertArrayNotHasKey('motivation_ar', $this->today()->assertOk()->json());
    }

    // ---- who can read it ---------------------------------------------------------------------------------------------------

    public function test_the_nutritionist_previews_exactly_what_the_patient_sees(): void
    {
        $plan = $this->plan();
        $this->meal($plan, 'breakfast');
        PatientCheckin::create(['subscriber_id' => $this->subscriber->id, 'due_on' => '2026-10-26', 'status' => 'pending']);

        $mine = $this->today()->json();
        $preview = $this->getJson("/api/v1/clients/{$this->subscriber->id}/today", $this->nutritionistToken)->assertOk()->json();

        $this->assertSame($mine, $preview);
    }

    public function test_the_preview_is_for_the_patients_own_nutritionist_only(): void
    {
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/today", $this->strangerToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/today", $this->clientToken)->assertForbidden();
        $this->getJson('/api/v1/me/today', $this->nutritionistToken)->assertForbidden();
    }

    public function test_each_patient_gets_only_their_own_day(): void
    {
        $other = $this->otherSubscriber;
        $otherToken = $this->otherClientToken;

        $plan = $this->plan('active', null, $other);
        $this->meal($plan, 'dinner');
        PatientCheckin::create(['subscriber_id' => $other->id, 'due_on' => '2026-10-26', 'status' => 'pending']);

        $this->assertSame(['dinner'], array_column($this->today($otherToken)->json('meals'), 'name'));
        $this->assertSame([], $this->today()->json('meals'));
        $this->assertSame([], $this->types());
    }

    // ---- morning:send -------------------------------------------------------------------------------------------------------------

    private function morning(): void
    {
        $this->artisan('morning:send')->assertSuccessful();
    }

    public function test_the_push_is_one_generic_message_with_nothing_from_the_health_file(): void
    {
        $plan = $this->plan();
        $this->meal($plan, 'breakfast');
        PatientCheckin::create(['subscriber_id' => $this->subscriber->id, 'due_on' => '2026-10-26', 'status' => 'pending']);

        $this->morning();

        $this->assertCount(1, $this->pushes);
        $this->assertSame('patient-device', $this->pushes[0]['token']);
        $this->assertSame('صباح الخير! خطة يومك جاهزة', $this->pushes[0]['body']);
        $this->assertSame('morning', $this->pushes[0]['data']['category']);
        $this->assertSame('morning_message', $this->pushes[0]['data']['type']);
        $this->assertSame(['notification_id', 'category', 'type'], array_keys($this->pushes[0]['data']), 'ids only, no content');
        foreach (['الفطور', 'breakfast', 'استبيان', 'موعد'] as $leak) {
            $this->assertStringNotContainsString($leak, json_encode($this->pushes, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_it_is_sent_once_a_day_even_if_the_job_runs_again(): void
    {
        $this->meal($this->plan(), 'breakfast');

        $this->morning();
        $this->morning();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00', config('scheduling.timezone')));
        $this->morning();

        $this->assertCount(1, $this->pushes);
        $this->assertSame(1, PatientNotification::where('type', 'morning_message')->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-27 07:30', config('scheduling.timezone')));
        $this->morning();
        $this->assertCount(2, $this->pushes, 'a new day, a new message');
        $this->assertSame(2, PatientNotification::where('type', 'morning_message')->count());
    }

    public function test_only_patients_with_an_active_started_plan_get_it(): void
    {
        $make = function (string $status, ?string $start = null, bool $archived = false): User {
            $user = User::factory()->client($this->nutritionist)->create(['fcm_token' => "device-{$status}-{$start}-{$archived}"]);
            $s = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $user->id, 'archived_at' => $archived ? now() : null]);
            $this->plan($status, $start, $s);

            return $user;
        };

        $make('draft');
        $make('archived');
        $make('active', '2026-10-30');   // starts later
        $make('active', null, true);     // follow-up ended
        $noPlan = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => User::factory()->client($this->nutritionist)->create(['fcm_token' => 'device-noplan'])->id]);
        $good = $make('active', '2026-10-26');

        $this->morning();

        $this->assertSame([$good->fcm_token], array_column($this->pushes, 'token'));
        $this->assertSame(0, PatientNotification::where('user_id', $noPlan->user_id)->count());
    }

    public function test_a_patient_who_switched_the_morning_category_off_gets_no_push(): void
    {
        $this->meal($this->plan(), 'breakfast');
        $this->putJson('/api/v1/me/notification-preferences', ['morning' => false], $this->clientToken)->assertOk()->assertJsonPath('categories.morning', false);

        $this->morning();

        $this->assertSame([], $this->pushes);
        $row = PatientNotification::where('type', 'morning_message')->first();
        $this->assertSame('category_off', $row->push_skipped);
    }

    public function test_quiet_hours_still_apply_to_the_morning_push(): void
    {
        $this->meal($this->plan(), 'breakfast');
        // The default quiet hours end at 07:00 in the clinic's time: 07:30 is fine, 06:30 is not.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 06:30', config('scheduling.timezone')));
        $this->morning();
        $this->assertSame([], $this->pushes);
        $this->assertSame('quiet_hours', PatientNotification::where('type', 'morning_message')->value('push_skipped'));
    }

    public function test_the_job_records_its_run_for_the_self_scheduler(): void
    {
        $this->meal($this->plan(), 'breakfast');

        $this->morning();

        $scheduler = app(SelfScheduler::class);
        $this->assertNotNull($scheduler->lastRan('morning'));
        $this->assertSame(1, $scheduler->lastResult('morning')['sent']);
        $this->assertNotContains('morning', $scheduler->overdue(), 'ran after its slot today');
    }

    public function test_the_job_is_in_the_clinic_schedule_at_0730(): void
    {
        $this->assertSame('morning:send', SelfScheduler::TASKS['morning']);
        $this->assertEquals(
            CarbonImmutable::parse('2026-10-26 07:30', config('scheduling.timezone'))->utc(),
            app(SelfScheduler::class)->latestSlot('morning', CarbonImmutable::now()),
        );

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'morning:send'));
        $this->assertCount(1, $events);
        $this->assertSame('30 7 * * *', $events->first()->expression);
    }

    public function test_one_unreachable_patient_does_not_stop_the_rest(): void
    {
        $this->meal($this->plan(), 'breakfast');
        $user = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'second-device']);
        $second = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $user->id]);
        $this->meal($this->plan('active', null, $second), 'lunch');

        // Subscriber without a login account is skipped, not an error.
        DB::table('users')->where('id', $this->client->id)->update(['fcm_token' => null]);

        $this->morning();

        $this->assertSame(['second-device'], array_column($this->pushes, 'token'));
        $this->assertSame(2, PatientNotification::where('type', 'morning_message')->count(), 'both get the inbox row');
    }

    public function test_the_visible_dates_use_the_clinic_day(): void
    {
        // 22:30 UTC on the 25th is already the 26th in Riyadh.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 22:30', 'UTC'));

        $this->assertSame('2026-10-26', ClinicDay::today()->toDateString());
        $this->assertSame('2026-10-26', $this->today()->json('date'));
    }
}
