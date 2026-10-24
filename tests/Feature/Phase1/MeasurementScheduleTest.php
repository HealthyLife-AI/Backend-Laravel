<?php

namespace Tests\Feature\Phase1;

use App\Models\Alert;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\SubscriberMeasurementSchedule;
use App\Models\User;
use App\Services\Notifications\FcmPushService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 1, B: the measurement schedule. Days are the clinic's. The push goes
 * through a recording stand-in for FCM, so what leaves the server is visible.
 */
class MeasurementScheduleTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    /** Tokens are issued before time is moved: the JWT check reads the real clock. */
    private array $nutritionistToken;

    private array $clientToken;

    private array $strangerToken;

    private array $otherClientToken;

    /** @var list<array{title: string, body: string, data: array<string, string>}> */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $pushed = &$this->pushed;
        $this->app->instance(FcmPushService::class, new class($pushed) extends FcmPushService
        {
            public function __construct(private array &$log) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $deviceToken, string $title, string $body, array $data = []): void
            {
                $this->log[] = ['title' => $title, 'body' => $body, 'data' => $data];
            }
        });

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'device-1']);
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $otherClient = User::factory()->client($this->nutritionist)->create();
        Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherClient->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($otherClient);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());

        // 10:00 clinic time on a fixed day: outside any quiet hours, and "today" is stable.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    private function token(): array
    {
        return $this->nutritionistToken;
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/clients/{$this->subscriber->id}/measurement-schedule{$suffix}";
    }

    private function today(): string
    {
        return ClinicDay::today()->toDateString();
    }

    private function day(int $offset): string
    {
        return ClinicDay::today()->addDays($offset)->toDateString();
    }

    private function schedule(int $interval, int $dueOffset): SubscriberMeasurementSchedule
    {
        return SubscriberMeasurementSchedule::create([
            'subscriber_id' => $this->subscriber->id, 'interval_days' => $interval, 'next_due_on' => $this->day($dueOffset),
        ]);
    }

    private function remind(): void
    {
        $this->artisan('measurements:remind')->assertSuccessful();
    }

    // ---- nutritionist endpoints ---------------------------------------------

    public function test_no_schedule_reads_as_null_and_set_then_reset_work(): void
    {
        $this->assertSame('null', $this->getJson($this->url(), $this->token())->assertOk()->getContent(), 'the JSON literal null, not {}');

        $this->putJson($this->url(), ['interval_days' => 14], $this->token())
            ->assertOk()->assertJsonPath('interval_days', 14)->assertJsonPath('next_due_on', $this->day(14))
            ->assertJsonPath('is_overdue', false)->assertJsonPath('days_overdue', 0);

        // Interval and date reset together.
        $this->putJson($this->url(), ['interval_days' => 7, 'next_due_on' => $this->day(2)], $this->token())
            ->assertOk()->assertJsonPath('interval_days', 7)->assertJsonPath('next_due_on', $this->day(2));

        $this->assertSame(1, SubscriberMeasurementSchedule::count(), 'one schedule per patient');

        $this->deleteJson($this->url(), [], $this->token())->assertNoContent();
        $this->assertSame('null', $this->getJson($this->url(), $this->token())->getContent());
    }

    public function test_the_interval_and_date_are_validated(): void
    {
        foreach ([2, 91, 0, 'x', null] as $bad) {
            $this->putJson($this->url(), ['interval_days' => $bad], $this->token())->assertStatus(422)->assertJsonValidationErrors('interval_days');
        }
        foreach ([3, 7, 14, 30, 45, 90] as $ok) {
            $this->putJson($this->url(), ['interval_days' => $ok], $this->token())->assertOk();
        }

        $this->putJson($this->url(), ['interval_days' => 14, 'next_due_on' => $this->day(-1)], $this->token())->assertStatus(422)->assertJsonValidationErrors('next_due_on');
        $this->putJson($this->url(), ['interval_days' => 14, 'next_due_on' => 'tomorrow-ish'], $this->token())->assertStatus(422)->assertJsonValidationErrors('next_due_on');
        $this->putJson($this->url(), ['interval_days' => 14, 'next_due_on' => $this->today()], $this->token())->assertOk();
    }

    public function test_ownership_the_client_role_and_follow_up(): void
    {
        $stranger = $this->strangerToken;
        $this->getJson($this->url(), $stranger)->assertNotFound();
        $this->putJson($this->url(), ['interval_days' => 14], $stranger)->assertNotFound();
        $this->deleteJson($this->url(), [], $stranger)->assertNotFound();

        $clientToken = $this->clientToken;
        $this->putJson($this->url(), ['interval_days' => 14], $clientToken)->assertForbidden();
        $this->getJson($this->url(), $clientToken)->assertForbidden();

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->putJson($this->url(), ['interval_days' => 14], $this->token())->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson($this->url(), $this->token())->assertOk();
    }

    // ---- recording a reading moves the due date -----------------------------

    public function test_the_nutritionists_reading_moves_the_next_due_date_to_that_day_plus_the_interval(): void
    {
        $this->schedule(14, 3);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => $this->today(), 'weight_kg' => 80], $this->token())->assertCreated();

        $this->assertSame($this->day(14), SubscriberMeasurementSchedule::first()->next_due_on->toDateString());
    }

    public function test_the_patients_own_measurement_moves_it_too(): void
    {
        $this->schedule(7, 0);
        // Subscriber is active; the patient posts today's weight.
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 79, 'recorded_at' => $this->today()], $this->consented())->assertCreated();

        $this->assertSame($this->day(7), SubscriberMeasurementSchedule::first()->next_due_on->toDateString());
    }

    public function test_a_reading_entered_late_for_an_old_date_never_pulls_the_due_date_back(): void
    {
        $this->schedule(14, 14);
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => $this->today(), 'weight_kg' => 80], $this->token())->assertCreated();
        $this->assertSame($this->day(14), SubscriberMeasurementSchedule::first()->next_due_on->toDateString());

        // An older visit recorded afterwards: the latest reading still decides.
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => $this->day(-20), 'weight_kg' => 82], $this->token())->assertCreated();

        $this->assertSame($this->day(14), SubscriberMeasurementSchedule::first()->next_due_on->toDateString());
    }

    public function test_a_reading_with_no_schedule_creates_none(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => $this->today(), 'weight_kg' => 80], $this->token())->assertCreated();

        $this->assertSame(0, SubscriberMeasurementSchedule::count());
    }

    // ---- the daily command --------------------------------------------------

    public function test_the_reminder_is_pushed_once_on_the_due_day_with_generic_text(): void
    {
        $this->schedule(14, 0);

        $this->remind();

        $this->assertCount(1, $this->pushed);
        $this->assertSame('حان موعد تسجيل قياساتك', $this->pushed[0]['body']);
        $this->assertSame('measurement_due', $this->pushed[0]['data']['type']);
        $this->assertSame('measurements', $this->pushed[0]['data']['category']);
        $this->assertStringNotContainsStringIgnoringCase('kg', json_encode($this->pushed));
        $this->assertSame($this->today(), SubscriberMeasurementSchedule::first()->last_reminded_on->toDateString());

        // The same day again, and a later run the next day: still one push for this due date.
        $this->remind();
        $this->travel(1)->days();
        $this->remind();
        $this->assertCount(1, $this->pushed);
        $this->assertSame(1, PatientNotification::where('user_id', $this->client->id)->where('type', 'measurement_due')->count());
    }

    public function test_it_is_not_pushed_before_the_due_day_or_for_a_patient_without_a_schedule(): void
    {
        $this->schedule(14, 1);
        $other = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id]);

        $this->remind();

        $this->assertCount(0, $this->pushed);
        $this->assertNull(SubscriberMeasurementSchedule::first()->last_reminded_on);
        $this->assertNull($other->measurementSchedule);
    }

    public function test_a_new_due_date_after_a_reading_is_reminded_again_when_it_comes(): void
    {
        $this->schedule(7, 0);
        $this->remind();
        $this->assertCount(1, $this->pushed);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => $this->today(), 'weight_kg' => 80], $this->token())->assertCreated();
        $this->assertNull(SubscriberMeasurementSchedule::first()->last_reminded_on);

        $this->travel(7)->days();
        $this->remind();

        $this->assertCount(2, $this->pushed);
    }

    public function test_an_archived_patient_is_skipped(): void
    {
        $this->schedule(14, -5);
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->remind();

        $this->assertCount(0, $this->pushed);
        $this->assertSame(0, Alert::count());
    }

    public function test_the_overdue_alert_opens_after_three_days_and_a_reading_resolves_it(): void
    {
        $this->schedule(14, -2);
        $this->remind();
        $this->assertSame(0, Alert::where('type', Alert::TYPE_MEASUREMENT_OVERDUE)->count(), 'two days is not yet an alert');

        $this->travel(1)->days();
        $this->remind();
        $alert = Alert::where('type', Alert::TYPE_MEASUREMENT_OVERDUE)->sole();
        $this->assertNull($alert->resolved_at);
        $this->assertSame('normal', $alert->severity);

        // Another run does not open a second one.
        $this->remind();
        $this->assertSame(1, Alert::where('type', Alert::TYPE_MEASUREMENT_OVERDUE)->count());

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", ['recorded_at' => ClinicDay::today()->toDateString(), 'weight_kg' => 80], $this->token())->assertCreated();

        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_moving_the_date_forward_resolves_the_alert_and_removing_the_schedule_does_too(): void
    {
        $this->schedule(14, -10);
        $this->remind();
        $alert = Alert::where('type', Alert::TYPE_MEASUREMENT_OVERDUE)->sole();

        $this->putJson($this->url(), ['interval_days' => 14, 'next_due_on' => $this->day(5)], $this->token())->assertOk();
        $this->assertNotNull($alert->fresh()->resolved_at);

        $this->putJson($this->url(), ['interval_days' => 14, 'next_due_on' => $this->today()], $this->token())->assertOk();
        $this->travel(4)->days();
        $this->remind();
        $second = Alert::where('type', Alert::TYPE_MEASUREMENT_OVERDUE)->whereNull('resolved_at')->sole();

        $this->deleteJson($this->url(), [], $this->token())->assertNoContent();
        $this->assertNotNull($second->fresh()->resolved_at);
    }

    public function test_it_is_scheduled_at_nine_clinic_time_and_self_triggers(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'measurements:remind'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame(config('scheduling.timezone'), (string) $event->timezone);
        $this->assertSame('measurements:remind', \App\Services\Scheduling\SelfScheduler::TASKS['measurements']);
    }

    // ---- the patient's view -------------------------------------------------

    public function test_the_patient_reads_their_schedule_or_null_and_sees_nothing_else(): void
    {
        $token = $this->consented();

        $this->assertSame('null', $this->getJson('/api/v1/me/measurement-schedule', $token)->assertOk()->getContent());

        $schedule = $this->schedule(30, 10);
        $schedule->forceFill(['last_reminded_on' => $this->today(), 'updated_by' => $this->nutritionist->id])->save();

        $this->getJson('/api/v1/me/measurement-schedule', $token)->assertOk()
            ->assertExactJson(['interval_days' => 30, 'next_due_on' => $this->day(10)]);
    }

    public function test_a_patient_reads_only_their_own_schedule(): void
    {
        $this->schedule(30, 10);
        $this->assertSame('null', $this->getJson('/api/v1/me/measurement-schedule', $this->otherClientToken)->assertOk()->getContent());
    }

    private function consented(): array
    {
        return $this->clientToken;
    }
}
