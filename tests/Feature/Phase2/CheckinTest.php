<?php

namespace Tests\Feature\Phase2;

use App\Models\Alert;
use App\Models\PatientCheckin;
use App\Models\PatientCondition;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\SubscriberCheckinSetting;
use App\Models\User;
use App\Services\Notifications\FcmPushService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 2, A: the periodic check-in questionnaire. Days are the clinic's.
 * Tokens are issued before time moves (the JWT check reads the real clock) and
 * pushes go through a recording stand-in for FCM.
 */
class CheckinTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private Subscriber $otherSubscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $otherClientToken;

    private array $strangerToken;

    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
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
                $this->log[] = ['token' => $deviceToken, 'title' => $title, 'body' => $body, 'data' => $data];
            }
        });

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'patient-device']);
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $otherClient = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherClient->id]);

        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($otherClient);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());

        // 10:00 clinic time: outside any quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    private function day(int $offset = 0): string
    {
        return ClinicDay::today()->addDays($offset)->toDateString();
    }

    private function settingsUrl(?Subscriber $s = null): string
    {
        return '/api/v1/clients/'.($s ?? $this->subscriber)->id.'/checkin-settings';
    }

    private function configure(array $body, ?array $token = null)
    {
        return $this->putJson($this->settingsUrl(), $body, $token ?? $this->nutritionistToken);
    }

    private function good(array $override = []): array
    {
        return $override + ['adherence' => 'good', 'barriers' => ['time'], 'appetite' => 'normal', 'sleep' => 'good'];
    }

    private function pending(?Subscriber $s = null, int $dueOffset = 0): PatientCheckin
    {
        return PatientCheckin::create(['subscriber_id' => ($s ?? $this->subscriber)->id, 'due_on' => $this->day($dueOffset), 'status' => 'pending']);
    }

    private function submit(PatientCheckin $c, array $answers, ?array $token = null)
    {
        return $this->postJson("/api/v1/me/checkins/{$c->id}", ['answers' => $answers], $token ?? $this->clientToken);
    }

    private function open(): void
    {
        $this->artisan('checkins:open')->assertSuccessful();
    }

    // ---- settings -------------------------------------------------------------

    public function test_no_settings_reads_as_the_json_null_literal(): void
    {
        $this->assertSame('null', $this->getJson($this->settingsUrl(), $this->nutritionistToken)->assertOk()->getContent());
    }

    public function test_setting_it_for_today_opens_the_first_check_in_at_once_pushes_once_and_moves_the_date(): void
    {
        $this->configure(['interval_days' => 7])->assertOk()->assertJsonPath('interval_days', 7)->assertJsonPath('next_due_on', $this->day(7));

        $checkin = PatientCheckin::where('subscriber_id', $this->subscriber->id)->sole();
        $this->assertSame($this->day(), $checkin->due_on->toDateString());
        $this->assertSame('pending', $checkin->status);

        $this->assertCount(1, $this->pushed);
        $this->assertSame('patient-device', $this->pushed[0]['token']);
        $this->assertSame('عندك استبيان قصير من أخصائيتك', $this->pushed[0]['body']);
        $this->assertSame('checkin_due', $this->pushed[0]['data']['type']);
        $everything = json_encode($this->pushed, JSON_UNESCAPED_UNICODE);
        foreach (['سكري', 'ضغط', $this->client->name] as $leak) {
            $this->assertStringNotContainsString($leak, $everything);
        }
    }

    public function test_a_future_date_waits_for_the_command_and_a_second_run_does_nothing_more(): void
    {
        $this->configure(['interval_days' => 14, 'next_due_on' => $this->day(2)])->assertOk()->assertJsonPath('next_due_on', $this->day(2));
        $this->assertSame(0, PatientCheckin::count());
        $this->assertCount(0, $this->pushed);

        $this->open();
        $this->assertSame(0, PatientCheckin::count(), 'not due yet');

        $this->travel(2)->days();
        $this->open();
        $this->open();

        $this->assertSame(1, PatientCheckin::count(), 'once per due date');
        $this->assertCount(1, $this->pushed);
        $this->assertSame($this->day(14), SubscriberCheckinSetting::first()->next_due_on->toDateString());
        $this->assertSame(1, PatientNotification::where('user_id', $this->client->id)->where('type', 'checkin_due')->count());
    }

    public function test_the_next_one_opens_when_its_date_comes(): void
    {
        $this->configure(['interval_days' => 7])->assertOk();
        $this->travel(7)->days();
        $this->open();

        $this->assertSame([$this->day(-7), $this->day()], PatientCheckin::orderBy('due_on')->get()->map(fn ($c) => $c->due_on->toDateString())->all());
        $this->assertSame($this->day(7), SubscriberCheckinSetting::first()->next_due_on->toDateString());
        $this->assertCount(2, $this->pushed);
    }

    public function test_a_long_gap_opens_one_check_in_and_skips_the_missed_cycles(): void
    {
        SubscriberCheckinSetting::create(['subscriber_id' => $this->subscriber->id, 'interval_days' => 7, 'next_due_on' => $this->day(-20)]);

        $this->open();

        $this->assertSame(1, PatientCheckin::count());
        $this->assertSame($this->day(-20), PatientCheckin::first()->due_on->toDateString());
        $this->assertSame($this->day(1), SubscriberCheckinSetting::first()->next_due_on->toDateString(), 'moved past today in whole intervals');
    }

    public function test_a_newer_check_in_replaces_one_still_waiting(): void
    {
        $old = $this->pending(null, -7);
        $this->configure(['interval_days' => 7])->assertOk();

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('pending', PatientCheckin::whereDate('due_on', $this->day())->sole()->status);
    }

    public function test_off_with_null_and_delete_stop_it(): void
    {
        $this->configure(['interval_days' => 7])->assertOk();
        $this->configure(['interval_days' => null])->assertOk()->assertJsonPath('interval_days', null)->assertJsonPath('next_due_on', null);

        $this->travel(30)->days();
        $this->open();
        $this->assertSame(1, PatientCheckin::count(), 'nothing opens while off');

        $this->configure(['interval_days' => 14])->assertOk();
        $this->deleteJson($this->settingsUrl(), [], $this->nutritionistToken)->assertNoContent();
        $this->assertSame('null', $this->getJson($this->settingsUrl(), $this->nutritionistToken)->getContent());
    }

    public function test_the_interval_and_date_are_validated(): void
    {
        foreach ([3, 10, 30, 'x'] as $bad) {
            $this->configure(['interval_days' => $bad])->assertStatus(422)->assertJsonValidationErrors('interval_days');
        }
        $this->putJson($this->settingsUrl(), [], $this->nutritionistToken)->assertStatus(422)->assertJsonValidationErrors('interval_days');
        $this->configure(['interval_days' => 7, 'next_due_on' => $this->day(-1)])->assertStatus(422)->assertJsonValidationErrors('next_due_on');
    }

    public function test_ownership_the_client_role_and_follow_up(): void
    {
        $this->getJson($this->settingsUrl(), $this->strangerToken)->assertNotFound();
        $this->configure(['interval_days' => 7], $this->strangerToken)->assertNotFound();
        $this->deleteJson($this->settingsUrl(), [], $this->strangerToken)->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/checkins", $this->strangerToken)->assertNotFound();

        $this->configure(['interval_days' => 7], $this->clientToken)->assertForbidden();
        $this->getJson($this->settingsUrl(), $this->clientToken)->assertForbidden();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/checkins", $this->clientToken)->assertForbidden();

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->configure(['interval_days' => 7])->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson($this->settingsUrl(), $this->nutritionistToken)->assertOk();
    }

    public function test_an_archived_patient_is_skipped_by_the_command(): void
    {
        SubscriberCheckinSetting::create(['subscriber_id' => $this->subscriber->id, 'interval_days' => 7, 'next_due_on' => $this->day()]);
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->open();

        $this->assertSame(0, PatientCheckin::count());
        $this->assertCount(0, $this->pushed);
    }

    // ---- expiry -----------------------------------------------------------------

    public function test_a_pending_one_expires_after_seven_days_without_an_alert(): void
    {
        $seven = $this->pending(null, -7);
        $eight = $this->pending($this->otherSubscriber, -8);

        $this->open();

        $this->assertSame('pending', $seven->fresh()->status, 'seven days old is not older than seven days');
        $this->assertSame('expired', $eight->fresh()->status);
        $this->assertSame(0, Alert::count());

        $this->travel(1)->days();
        $this->open();
        $this->assertSame('expired', $seven->fresh()->status);

        // An expired one can no longer be answered.
        $this->submit($seven, $this->good())->assertStatus(409)->assertJsonPath('code', 'checkin_expired');
    }

    // ---- patient API ----------------------------------------------------------------

    public function test_current_is_the_null_literal_or_the_question_list(): void
    {
        $this->assertSame('null', $this->getJson('/api/v1/me/checkins/current', $this->clientToken)->assertOk()->getContent());

        $checkin = $this->pending();
        $current = $this->getJson('/api/v1/me/checkins/current', $this->clientToken)->assertOk()->json();

        $this->assertSame($checkin->id, $current['id']);
        $this->assertSame($this->day(), $current['due_on']);
        $this->assertSame(['adherence', 'barriers', 'appetite', 'sleep', 'note'], array_column($current['questions'], 'key'));

        $questions = collect($current['questions'])->keyBy('key');
        $this->assertSame('single', $questions['adherence']['type']);
        $this->assertSame('كيف كان التزامك بالخطة هالفترة؟', $questions['adherence']['label']);
        $this->assertSame([['value' => 'excellent', 'label' => 'ممتاز'], ['value' => 'good', 'label' => 'جيد'], ['value' => 'fair', 'label' => 'متوسط'], ['value' => 'poor', 'label' => 'ضعيف']], $questions['adherence']['options']);
        $this->assertSame('multi', $questions['barriers']['type']);
        $this->assertSame(3, $questions['barriers']['max']);
        $this->assertCount(8, $questions['barriers']['options']);
        $this->assertSame('text', $questions['note']['type']);
        $this->assertSame(280, $questions['note']['max']);
        $this->assertSame([], $questions['note']['options']);

        // Nothing about flagging is sent.
        $this->assertStringNotContainsString('flag', json_encode($current));
    }

    public function test_a_valid_submission_is_stored_with_the_questionnaire_version_and_returns_201(): void
    {
        $checkin = $this->pending();

        $response = $this->submit($checkin, $this->good(['note' => '  تعبت قليلًا  ']))->assertCreated();

        $response->assertJsonPath('status', 'submitted')->assertJsonPath('answers.note', 'تعبت قليلًا')->assertJsonPath('answers.adherence', 'good');
        $this->assertSame(1, $checkin->fresh()->questionnaire_version);
        $this->assertNotNull($checkin->fresh()->submitted_at);
        // The nutritionist's side of it never reaches the patient.
        foreach (['flagged', 'reviewed_at', 'reviewed_by'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $response->json());
        }
        $this->assertSame('null', $this->getJson('/api/v1/me/checkins/current', $this->clientToken)->getContent(), 'it is no longer current');
    }

    public function test_answers_are_validated_against_the_config(): void
    {
        $checkin = $this->pending();
        $cases = [
            'missing adherence' => [['barriers' => ['time'], 'appetite' => 'normal', 'sleep' => 'good'], 'answers.adherence'],
            'missing barriers' => [['adherence' => 'good', 'appetite' => 'normal', 'sleep' => 'good'], 'answers.barriers'],
            'empty barriers' => [$this->good(['barriers' => []]), 'answers.barriers'],
            'bad option' => [$this->good(['adherence' => 'amazing']), 'answers.adherence'],
            'list for a single' => [$this->good(['sleep' => ['good']]), 'answers.sleep'],
            'string for a multi' => [$this->good(['barriers' => 'time']), 'answers.barriers'],
            'four barriers' => [$this->good(['barriers' => ['time', 'budget', 'hunger', 'mood']]), 'answers.barriers'],
            'unknown barrier' => [$this->good(['barriers' => ['weather']]), 'answers.barriers'],
            'duplicate barrier' => [$this->good(['barriers' => ['time', 'time']]), 'answers.barriers'],
            'none with another' => [$this->good(['barriers' => ['none', 'time']]), 'answers.barriers'],
            'note too long' => [$this->good(['note' => str_repeat('ا', 281)]), 'answers.note'],
            'note not text' => [$this->good(['note' => ['x']]), 'answers.note'],
            'unknown question' => [$this->good(['mood_today' => 'ok']), 'answers.mood_today'],
        ];

        foreach ($cases as $name => [$answers, $field]) {
            $this->submit($checkin, $answers)->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->postJson("/api/v1/me/checkins/{$checkin->id}", [], $this->clientToken)->assertStatus(422)->assertJsonValidationErrors('answers');

        $this->assertSame('pending', $checkin->fresh()->status, 'nothing was stored');

        // The edges that ARE allowed.
        $this->submit($checkin, $this->good(['barriers' => ['none'], 'note' => str_repeat('ا', 280)]))->assertCreated();
    }

    public function test_an_empty_note_is_dropped_and_the_three_barrier_limit_is_inclusive(): void
    {
        $this->submit($this->pending(), $this->good(['barriers' => ['time', 'budget', 'occasions'], 'note' => '   ']))->assertCreated()
            ->assertJsonMissingPath('answers.note')->assertJsonCount(3, 'answers.barriers');
    }

    public function test_only_your_own_pending_check_in_can_be_answered_once(): void
    {
        $mine = $this->pending();
        $theirs = $this->pending($this->otherSubscriber);

        $this->submit($theirs, $this->good())->assertNotFound();
        $this->assertSame('pending', $theirs->fresh()->status);

        $this->submit($mine, $this->good())->assertCreated();
        $this->submit($mine, $this->good(['adherence' => 'poor']))->assertStatus(409)->assertJsonPath('code', 'checkin_already_submitted');
        $this->assertSame('good', $mine->fresh()->answers['adherence']);

        $this->postJson("/api/v1/me/checkins/{$mine->id}", ['answers' => $this->good()], $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/me/checkins/current', $this->nutritionistToken)->assertForbidden();
    }

    public function test_the_history_is_own_newest_first_and_the_last_ten(): void
    {
        foreach (range(1, 12) as $i) {
            PatientCheckin::create(['subscriber_id' => $this->subscriber->id, 'due_on' => $this->day(-7 * $i), 'status' => 'submitted', 'answers' => $this->good(), 'submitted_at' => now(), 'questionnaire_version' => 1]);
        }
        $this->pending($this->otherSubscriber);

        $history = $this->getJson('/api/v1/me/checkins', $this->clientToken)->assertOk()->json();

        $this->assertCount(10, $history);
        $this->assertSame($this->day(-7), $history[0]['due_on']);
        $this->assertSame($this->day(-70), $history[9]['due_on']);
        $this->assertSame(['id', 'due_on', 'status', 'submitted_at', 'answers'], array_keys($history[0]));
        $this->getJson('/api/v1/me/checkins', $this->otherClientToken)->assertJsonCount(1);
    }

    // ---- flagging ---------------------------------------------------------------------

    public function test_each_flag_rule_flags_and_opens_a_linked_alert(): void
    {
        $cases = [
            'poor adherence' => $this->good(['adherence' => 'poor']),
            'hunger barrier' => $this->good(['barriers' => ['time', 'hunger']]),
            'mood barrier' => $this->good(['barriers' => ['mood']]),
            'higher appetite' => $this->good(['appetite' => 'higher']),
        ];

        foreach ($cases as $name => $answers) {
            $checkin = $this->pending(null, -array_search($name, array_keys($cases)) - 1);
            $this->submit($checkin, $answers)->assertCreated();

            $this->assertTrue($checkin->fresh()->flagged, $name);
            $alert = Alert::where('checkin_id', $checkin->id)->sole();
            $this->assertSame(Alert::TYPE_CHECKIN_FLAG, $alert->type, $name);
            $this->assertNull($alert->resolved_at);
            $this->assertSame('A check-in needs attention.', $alert->message);
            $this->assertStringNotContainsString('poor', $alert->message);
        }
    }

    public function test_a_clean_check_in_is_not_flagged_and_opens_no_alert(): void
    {
        foreach ([$this->good(), $this->good(['adherence' => 'fair', 'appetite' => 'lower', 'sleep' => 'short', 'barriers' => ['time', 'budget', 'food_availability']])] as $i => $answers) {
            $checkin = $this->pending(null, -$i - 1);
            $this->submit($checkin, $answers)->assertCreated();
            $this->assertFalse($checkin->fresh()->flagged);
        }

        $this->assertSame(0, Alert::count());
    }

    public function test_the_alert_is_important_only_for_the_watch_list(): void
    {
        $this->submit($this->pending(), $this->good(['adherence' => 'poor']))->assertCreated();
        $this->assertSame('normal', Alert::sole()->severity);

        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => 'hypertension']);
        $second = $this->pending(null, -1);
        $this->submit($second, $this->good(['adherence' => 'poor']))->assertCreated();
        $this->assertSame('important', Alert::where('checkin_id', $second->id)->sole()->severity);

        PatientCondition::where('subscriber_id', $this->subscriber->id)->delete();
        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => 'kidney_disease']);
        $third = $this->pending(null, -2);
        $this->submit($third, $this->good(['appetite' => 'higher']))->assertCreated();
        $this->assertSame('important', Alert::where('checkin_id', $third->id)->sole()->severity);

        // Edited through the config, no code.
        config(['alerts.severity.important_types' => ['no_log']]);
        $fourth = $this->pending(null, -3);
        $this->submit($fourth, $this->good(['appetite' => 'higher']))->assertCreated();
        $this->assertSame('normal', Alert::where('checkin_id', $fourth->id)->sole()->severity);
    }

    public function test_reviewing_resolves_exactly_its_own_alert(): void
    {
        $first = $this->pending(null, -1);
        $second = $this->pending(null, 0);
        $this->submit($first, $this->good(['adherence' => 'poor']))->assertCreated();
        $this->submit($second, $this->good(['appetite' => 'higher']))->assertCreated();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/checkins/{$second->id}/review", [], $this->nutritionistToken)
            ->assertOk()->assertJsonPath('flagged', true);

        $this->assertNotNull($second->fresh()->reviewed_at);
        $this->assertSame($this->nutritionist->id, $second->fresh()->reviewed_by);
        $this->assertNotNull(Alert::where('checkin_id', $second->id)->sole()->resolved_at);
        $this->assertNull(Alert::where('checkin_id', $first->id)->sole()->resolved_at, 'the other check-in is still waiting');

        // Again: nothing changes.
        $reviewedAt = $second->fresh()->reviewed_at;
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/checkins/{$second->id}/review", [], $this->nutritionistToken)->assertOk();
        $this->assertTrue($reviewedAt->equalTo($second->fresh()->reviewed_at));
    }

    public function test_deleting_a_check_in_keeps_the_alert_with_no_link(): void
    {
        $checkin = $this->pending();
        $this->submit($checkin, $this->good(['adherence' => 'poor']))->assertCreated();
        $alert = Alert::sole();

        $checkin->delete();

        $this->assertNull($alert->fresh()->checkin_id);
        $this->assertNotNull($alert->fresh());
    }

    public function test_the_nutritionist_reads_the_history_in_arabic_with_the_flags(): void
    {
        $checkin = $this->pending();
        $this->submit($checkin, $this->good(['adherence' => 'poor', 'barriers' => ['hunger', 'time'], 'note' => 'ملاحظة']))->assertCreated();

        $history = $this->getJson("/api/v1/clients/{$this->subscriber->id}/checkins", $this->nutritionistToken)->assertOk()->json();

        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['flagged']);
        $this->assertNull($history[0]['reviewed_at']);
        $this->assertSame(1, $history[0]['questionnaire_version']);

        $lines = collect($history[0]['answers_display'])->keyBy('key');
        $this->assertSame('ضعيف', $lines['adherence']['value']);
        $this->assertTrue($lines['adherence']['flagged']);
        $this->assertSame('الجوع والشهية، الوقت', $lines['barriers']['value']);
        $this->assertSame('طبيعية', $lines['appetite']['value']);
        $this->assertFalse($lines['appetite']['flagged']);
        $this->assertSame('ملاحظة', $lines['note']['value']);
    }

    public function test_another_nutritionist_cannot_review_and_the_checkin_must_belong_to_the_patient(): void
    {
        $checkin = $this->pending();
        $this->submit($checkin, $this->good(['adherence' => 'poor']))->assertCreated();

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/checkins/{$checkin->id}/review", [], $this->strangerToken)->assertNotFound();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/checkins/{$checkin->id}/review", [], $this->clientToken)->assertForbidden();
        // Another patient's id under this patient's path.
        $this->postJson("/api/v1/clients/{$this->otherSubscriber->id}/checkins/{$checkin->id}/review", [], $this->nutritionistToken)->assertNotFound();

        $this->assertNull($checkin->fresh()->reviewed_at);

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/checkins/{$checkin->id}/review", [], $this->nutritionistToken)->assertStatus(409);
    }

    public function test_it_is_scheduled_at_nine_clinic_time_and_self_triggers(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'checkins:open'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame(config('scheduling.timezone'), (string) $event->timezone);
        $this->assertSame('checkins:open', \App\Services\Scheduling\SelfScheduler::TASKS['checkins']);
    }
}
