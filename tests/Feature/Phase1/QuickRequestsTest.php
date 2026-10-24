<?php

namespace Tests\Feature\Phase1;

use App\Models\Alert;
use App\Models\PatientCondition;
use App\Models\PatientNotification;
use App\Models\PatientQuickRequest;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Notifications\FcmPushService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Phase 1, E: patient quick requests. Structured (a reason, a short note), sent
 * once, answered with plain text, never changing a plan. Pushes go through a
 * recording stand-in for FCM so what would leave the server can be read.
 */
class QuickRequestsTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    private array $nutritionistToken;

    private array $clientToken;

    private array $strangerToken;

    private array $otherClientToken;

    private Subscriber $otherSubscriber;

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

        $this->nutritionist = User::factory()->nutritionist()->create(['fcm_token' => 'nutritionist-device']);
        $this->client = User::factory()->client($this->nutritionist)->create(['fcm_token' => 'patient-device']);
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);

        $otherClient = User::factory()->client($this->nutritionist)->create();
        $this->otherSubscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $otherClient->id]);

        // Tokens first: the JWT check reads the real clock, so they are issued before time moves.
        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
        $this->clientToken = $this->bearerFor($this->client);
        $this->otherClientToken = $this->bearerFor($otherClient);
        $this->strangerToken = $this->bearerFor(User::factory()->nutritionist()->create());

        // 10:00 clinic time: outside any quiet hours.
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', config('scheduling.timezone')));
    }

    private function send(array $body = [], ?array $token = null)
    {
        return $this->postJson('/api/v1/me/quick-requests', $body + ['reason' => 'craving'], $token ?? $this->clientToken);
    }

    private function condition(string $code): void
    {
        PatientCondition::create(['subscriber_id' => $this->subscriber->id, 'code' => $code]);
    }

    // ---- patient: create ----------------------------------------------------------

    public function test_a_patient_sends_a_request_and_sees_it_with_no_response_yet(): void
    {
        $created = $this->send(['reason' => 'special_occasion', 'note' => 'عرس صديقتي', 'occasion_date' => ClinicDay::today()->addDays(3)->toDateString()])->assertCreated();

        $created->assertJsonPath('reason', 'special_occasion')->assertJsonPath('note', 'عرس صديقتي')
            ->assertJsonPath('status', 'open')->assertJsonPath('response', null)->assertJsonPath('responded_at', null)
            ->assertJsonPath('occasion_date', ClinicDay::today()->addDays(3)->toDateString());
        // Whose it is stays off the patient's own copy: they know.
        $this->assertArrayNotHasKey('subscriber_name', $created->json());

        $this->getJson('/api/v1/me/quick-requests', $this->clientToken)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $created->json('id'));
    }

    public function test_the_reason_is_required_and_fixed_and_the_note_is_short(): void
    {
        foreach (['', 'nonsense', null] as $reason) {
            $this->postJson('/api/v1/me/quick-requests', ['reason' => $reason], $this->clientToken)->assertStatus(422)->assertJsonValidationErrors('reason');
        }
        $this->postJson('/api/v1/me/quick-requests', [], $this->clientToken)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->send(['note' => str_repeat('ا', 281)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->send(['occasion_date' => ClinicDay::today()->subDay()->toDateString(), 'reason' => 'special_occasion'])->assertStatus(422)->assertJsonValidationErrors('occasion_date');

        foreach (PatientQuickRequest::REASONS as $reason) {
            $this->send(['reason' => $reason])->assertCreated();
        }
        $this->send(['note' => str_repeat('ا', 280)])->assertStatus(429, 'the sixth of the day');
    }

    public function test_the_occasion_date_is_kept_only_for_a_special_occasion(): void
    {
        $date = ClinicDay::today()->addDay()->toDateString();

        $this->send(['reason' => 'craving', 'occasion_date' => $date])->assertCreated()->assertJsonPath('occasion_date', null);
    }

    public function test_five_a_day_then_429_and_a_new_clinic_day_starts_again(): void
    {
        foreach (range(1, 5) as $i) {
            $this->send(['note' => "طلب {$i}"])->assertCreated();
        }

        $this->send()->assertStatus(429)->assertJsonPath('code', 'quick_request_limit');
        $this->assertSame(5, PatientQuickRequest::count());

        // The limit is per patient: another patient is unaffected.
        $this->send([], $this->otherClientToken)->assertCreated();

        $this->travel(1)->days();
        $this->send()->assertCreated();
    }

    public function test_a_request_cannot_be_edited_or_deleted_by_the_patient(): void
    {
        $id = $this->send()->assertCreated()->json('id');

        $this->patchJson("/api/v1/me/quick-requests/{$id}", ['note' => 'x'], $this->clientToken)->assertStatus(404);
        $this->putJson("/api/v1/me/quick-requests/{$id}", ['note' => 'x'], $this->clientToken)->assertStatus(404);
        $this->deleteJson("/api/v1/me/quick-requests/{$id}", [], $this->clientToken)->assertStatus(404);
    }

    public function test_a_patient_reads_only_their_own_newest_first_with_the_response(): void
    {
        $first = $this->send(['note' => 'الأول'])->json('id');
        $this->travel(1)->minutes();
        $second = $this->send(['note' => 'الثاني'])->json('id');
        $this->send(['note' => 'لمريض آخر'], $this->otherClientToken)->assertCreated();

        PatientQuickRequest::find($first)->forceFill(['status' => 'answered', 'response' => 'رد الأخصائية', 'responded_by' => $this->nutritionist->id, 'responded_at' => now()])->save();

        $list = $this->getJson('/api/v1/me/quick-requests', $this->clientToken)->assertOk()->json();

        $this->assertSame([$second, $first], array_column($list, 'id'));
        $this->assertSame('رد الأخصائية', $list[1]['response']);
        $this->assertSame('answered', $list[1]['status']);
        $this->assertNotContains('لمريض آخر', array_column($list, 'note'));
    }

    public function test_a_nutritionist_token_cannot_use_the_patient_endpoints(): void
    {
        $this->postJson('/api/v1/me/quick-requests', ['reason' => 'craving'], $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/me/quick-requests', $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/me/quick-requests')->assertUnauthorized();
    }

    // ---- alert + push ------------------------------------------------------------------

    public function test_it_opens_a_quick_request_alert_with_the_severity_rule(): void
    {
        $this->condition('diabetes_type2');

        $this->send(['reason' => 'craving'])->assertCreated();
        $this->send(['reason' => 'cannot_continue'])->assertCreated();
        $this->send(['reason' => 'special_occasion'])->assertCreated();
        $this->send(['reason' => 'food_unavailable'])->assertCreated();

        $severities = Alert::where('type', Alert::TYPE_QUICK_REQUEST)->orderBy('id')->pluck('severity')->all();
        $this->assertSame(['important', 'important', 'normal', 'normal'], $severities);
    }

    public function test_a_patient_off_the_watch_list_gets_a_normal_alert_even_for_a_craving(): void
    {
        $this->send(['reason' => 'craving'])->assertCreated();

        $alert = Alert::where('type', Alert::TYPE_QUICK_REQUEST)->sole();
        $this->assertSame('normal', $alert->severity);
        $this->assertNull($alert->resolved_at);
        $this->assertSame($this->subscriber->id, $alert->subscriber_id);
        $this->assertStringNotContainsString('craving', $alert->message);
    }

    public function test_the_nutritionist_gets_a_generic_push_with_no_health_data(): void
    {
        $this->condition('diabetes_type1');

        $this->send(['reason' => 'craving', 'note' => 'أشتهي الكنافة جدًا وسكري مرتفع'])->assertCreated();

        $this->assertCount(1, $this->pushed);
        $push = $this->pushed[0];
        $this->assertSame('nutritionist-device', $push['token']);
        $this->assertSame('طلب جديد من أحد المرضى', $push['body']);

        $everything = json_encode($push, JSON_UNESCAPED_UNICODE);
        foreach (['craving', 'الكنافة', 'سكري', $this->client->name, $this->subscriber->code] as $leak) {
            $this->assertStringNotContainsString((string) $leak, $everything, "the push must not carry: {$leak}");
        }
        // Ids only, for a deep link.
        $this->assertSame(['notification_id', 'category', 'type', 'request_id'], array_keys($push['data']));
    }

    public function test_no_device_means_no_push_and_no_failure(): void
    {
        $this->nutritionist->forceFill(['fcm_token' => null])->save();

        $this->send()->assertCreated();

        $this->assertCount(0, $this->pushed);
        $this->assertSame(1, PatientQuickRequest::count());
        $this->assertSame('no_token', PatientNotification::where('user_id', $this->nutritionist->id)->sole()->push_skipped);
    }

    // ---- nutritionist -------------------------------------------------------------------

    public function test_the_nutritionist_lists_one_patients_history_and_all_open_requests_with_a_count(): void
    {
        $a = $this->send(['note' => 'أ'])->json('id');
        $b = $this->send(['note' => 'ب'], $this->otherClientToken)->json('id');
        $c = $this->send(['note' => 'ج'])->json('id');
        PatientQuickRequest::find($a)->forceFill(['status' => 'closed'])->save();

        $history = $this->getJson("/api/v1/clients/{$this->subscriber->id}/quick-requests", $this->nutritionistToken)->assertOk()->json();
        $this->assertSame([$c, $a], array_column($history, 'id'));

        $open = $this->getJson('/api/v1/quick-requests?status=open', $this->nutritionistToken)->assertOk();
        $this->assertSame([$c, $b], array_column($open->json('data'), 'id'));
        $this->assertSame(2, $open->json('meta.total'), 'the sidebar badge');
        $this->assertSame($this->client->name, $open->json('data.0.subscriber_name'));
        $this->assertSame($this->subscriber->code, $open->json('data.0.subscriber_code'));
        $this->assertSame($this->subscriber->id, $open->json('data.0.subscriber_id'));

        $this->assertCount(3, $this->getJson('/api/v1/quick-requests', $this->nutritionistToken)->json('data'));
        $this->getJson('/api/v1/quick-requests?status=weird', $this->nutritionistToken)->assertStatus(422);
    }

    public function test_another_nutritionist_sees_none_of_it_and_cannot_act_on_it(): void
    {
        $id = $this->send()->json('id');

        $this->assertSame([], $this->getJson('/api/v1/quick-requests?status=open', $this->strangerToken)->assertOk()->json('data'));
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/quick-requests", $this->strangerToken)->assertNotFound();
        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'x'], $this->strangerToken)->assertNotFound();
        $this->postJson("/api/v1/quick-requests/{$id}/close", [], $this->strangerToken)->assertNotFound();

        $this->assertSame('open', PatientQuickRequest::find($id)->status);
    }

    public function test_a_patient_cannot_use_the_nutritionist_endpoints(): void
    {
        $id = $this->send()->json('id');

        $this->getJson('/api/v1/quick-requests', $this->clientToken)->assertForbidden();
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/quick-requests", $this->clientToken)->assertForbidden();
        $this->getJson("/api/v1/clients/{$this->otherSubscriber->id}/quick-requests", $this->clientToken)->assertForbidden();
        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'x'], $this->clientToken)->assertForbidden();
        $this->postJson("/api/v1/quick-requests/{$id}/close", [], $this->clientToken)->assertForbidden();

        $this->assertSame('open', PatientQuickRequest::find($id)->status);
    }

    public function test_responding_answers_resolves_the_alert_and_pushes_the_patient_generically(): void
    {
        $this->condition('hypertension');
        $id = $this->send(['reason' => 'craving', 'note' => 'سر الطلب'])->json('id');
        $this->pushed = [];

        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'يمكنك تناول حبة صغيرة اليوم.'], $this->nutritionistToken)
            ->assertOk()->assertJsonPath('status', 'answered')->assertJsonPath('response', 'يمكنك تناول حبة صغيرة اليوم.');

        $request = PatientQuickRequest::find($id);
        $this->assertSame('answered', $request->status);
        $this->assertSame($this->nutritionist->id, $request->responded_by);
        $this->assertNotNull($request->responded_at);
        $this->assertNotNull(Alert::where('type', Alert::TYPE_QUICK_REQUEST)->sole()->resolved_at);

        $this->assertCount(1, $this->pushed);
        $this->assertSame('patient-device', $this->pushed[0]['token']);
        $this->assertSame('وصلك رد من أخصائيتك. افتح التطبيق لقراءته.', $this->pushed[0]['body']);
        $everything = json_encode($this->pushed, JSON_UNESCAPED_UNICODE);
        foreach (['حبة صغيرة', 'سر الطلب', 'craving', 'ضغط'] as $leak) {
            $this->assertStringNotContainsString($leak, $everything);
        }

        // The patient reads the answer in the app.
        $this->getJson('/api/v1/me/quick-requests', $this->clientToken)->assertJsonPath('0.response', 'يمكنك تناول حبة صغيرة اليوم.');
    }

    public function test_the_response_is_required_plain_and_at_most_1000_characters(): void
    {
        $id = $this->send()->json('id');

        $this->postJson("/api/v1/quick-requests/{$id}/respond", [], $this->nutritionistToken)->assertStatus(422)->assertJsonValidationErrors('response');
        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => str_repeat('ا', 1001)], $this->nutritionistToken)->assertStatus(422)->assertJsonValidationErrors('response');
        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => str_repeat('ا', 1000)], $this->nutritionistToken)->assertOk();
    }

    public function test_only_an_open_request_can_be_answered(): void
    {
        $id = $this->send()->json('id');
        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'أول'], $this->nutritionistToken)->assertOk();

        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'ثان'], $this->nutritionistToken)->assertStatus(409)->assertJsonPath('code', 'quick_request_not_open');
        $this->assertSame('أول', PatientQuickRequest::find($id)->response);
    }

    public function test_closing_resolves_the_alert_sends_no_push_and_is_repeatable(): void
    {
        $id = $this->send()->json('id');
        $this->pushed = [];

        $this->postJson("/api/v1/quick-requests/{$id}/close", [], $this->nutritionistToken)->assertOk()->assertJsonPath('status', 'closed');
        $this->postJson("/api/v1/quick-requests/{$id}/close", [], $this->nutritionistToken)->assertOk()->assertJsonPath('status', 'closed');

        $this->assertNotNull(Alert::where('type', Alert::TYPE_QUICK_REQUEST)->sole()->resolved_at);
        $this->assertCount(0, $this->pushed, 'closing tells the patient nothing');

        // An answered one can be closed afterwards, keeping its response.
        $second = $this->send()->json('id');
        $this->postJson("/api/v1/quick-requests/{$second}/respond", ['response' => 'رد'], $this->nutritionistToken)->assertOk();
        $this->postJson("/api/v1/quick-requests/{$second}/close", [], $this->nutritionistToken)->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('response', 'رد');
    }

    public function test_alerts_follow_the_number_of_requests_still_waiting(): void
    {
        $first = $this->send()->json('id');
        $second = $this->send()->json('id');
        $this->assertSame(2, Alert::where('type', Alert::TYPE_QUICK_REQUEST)->open()->count());

        $this->postJson("/api/v1/quick-requests/{$first}/close", [], $this->nutritionistToken)->assertOk();
        $this->assertSame(1, Alert::where('type', Alert::TYPE_QUICK_REQUEST)->open()->count(), 'one request is still waiting');

        $this->postJson("/api/v1/quick-requests/{$second}/respond", ['response' => 'ok'], $this->nutritionistToken)->assertOk();
        $this->assertSame(0, Alert::where('type', Alert::TYPE_QUICK_REQUEST)->open()->count());
    }

    public function test_each_alert_is_linked_to_its_request(): void
    {
        $id = $this->send()->json('id');

        $this->assertSame($id, Alert::where('type', Alert::TYPE_QUICK_REQUEST)->sole()->quick_request_id);
    }

    public function test_answering_the_important_request_resolves_the_important_alert_and_leaves_the_normal_one(): void
    {
        $this->condition('diabetes_type2');
        $normalId = $this->send(['reason' => 'special_occasion'])->json('id');
        $importantId = $this->send(['reason' => 'craving'])->json('id');

        $normal = Alert::where('quick_request_id', $normalId)->sole();
        $important = Alert::where('quick_request_id', $importantId)->sole();
        $this->assertSame('normal', $normal->severity);
        $this->assertSame('important', $important->severity);

        $this->postJson("/api/v1/quick-requests/{$importantId}/respond", ['response' => 'رد'], $this->nutritionistToken)->assertOk();

        $this->assertNotNull($important->fresh()->resolved_at, 'its own alert is resolved');
        $this->assertNull($normal->fresh()->resolved_at, 'the other request is still waiting');
    }

    public function test_closing_resolves_its_own_alert_even_when_it_is_not_the_oldest(): void
    {
        $first = $this->send(['reason' => 'other'])->json('id');
        $second = $this->send(['reason' => 'craving'])->json('id');

        $this->postJson("/api/v1/quick-requests/{$second}/close", [], $this->nutritionistToken)->assertOk();

        $this->assertNotNull(Alert::where('quick_request_id', $second)->sole()->resolved_at);
        $this->assertNull(Alert::where('quick_request_id', $first)->sole()->resolved_at);
    }

    public function test_deleting_a_request_clears_the_link_and_keeps_the_alert(): void
    {
        $id = $this->send()->json('id');
        $alert = Alert::where('quick_request_id', $id)->sole();

        PatientQuickRequest::find($id)->delete();

        $alert = $alert->fresh();
        $this->assertNotNull($alert, 'the alert survives');
        $this->assertNull($alert->quick_request_id);
    }

    public function test_a_legacy_alert_without_a_link_falls_back_to_the_old_rule(): void
    {
        $id = $this->send()->json('id');
        // Make it look like an alert raised before the link existed.
        Alert::where('quick_request_id', $id)->update(['quick_request_id' => null]);

        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'رد'], $this->nutritionistToken)->assertOk();

        $this->assertSame(0, Alert::where('type', Alert::TYPE_QUICK_REQUEST)->open()->count());

        // And a legacy alert does not swallow a linked, still-waiting request's alert.
        $linked = $this->send()->json('id');
        $other = $this->send()->json('id');
        Alert::where('quick_request_id', $other)->update(['quick_request_id' => null]);
        $this->postJson("/api/v1/quick-requests/{$other}/close", [], $this->nutritionistToken)->assertOk();

        $this->assertNull(Alert::where('quick_request_id', $linked)->sole()->resolved_at);
    }

    public function test_an_archived_patients_requests_cannot_be_answered(): void
    {
        $id = $this->send()->json('id');
        $this->subscriber->forceFill(['archived_at' => now()])->save();

        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'x'], $this->nutritionistToken)->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->postJson("/api/v1/quick-requests/{$id}/close", [], $this->nutritionistToken)->assertStatus(409)->assertJsonPath('code', 'follow_up_ended');
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/quick-requests", $this->nutritionistToken)->assertOk();
    }

    public function test_answering_never_touches_the_plan(): void
    {
        $this->subscriber->forceFill(['last_logged_at' => now()])->save();
        $id = $this->send(['reason' => 'cannot_continue'])->json('id');

        $this->postJson("/api/v1/quick-requests/{$id}/respond", ['response' => 'سنعدّل الخطة معًا'], $this->nutritionistToken)->assertOk();

        $this->assertSame(0, $this->subscriber->mealPlans()->count());
    }
}
