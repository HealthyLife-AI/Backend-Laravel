<?php

namespace Tests\Feature\Appointments;

use App\Models\Appointment;
use App\Models\NotificationPreference;
use App\Models\PatientNotification;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/** Step 2: availability, slots, booking without double booking, the 12-hour lock, and the nutritionist's side. */
class AppointmentTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();
        $this->tz = (string) config('scheduling.timezone');
        // A fixed Sunday morning, so weekdays and the 12-hour lead are predictable.
        $this->travelTo(CarbonImmutable::parse('2026-10-11 08:00', $this->tz));

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->nutritionist->nutritionistProfile()->create(['whatsapp_number' => '+970599000000']);
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        NotificationPreference::for($this->patient->user)->update(['quiet_hours_enabled' => false]);
        $this->patient->user->forceFill(['fcm_token' => 'device'])->save();

        // Sunday to Thursday 09:00-12:00; Monday 2026-10-12 off.
        $this->putJson('/api/v1/me/availability', [
            'windows' => collect(range(0, 4))->map(fn ($d) => ['weekday' => $d, 'start' => '09:00', 'end' => '12:00'])->all(),
            'days_off' => ['2026-10-12'],
            'meeting_link' => null,
        ], $this->nurse())->assertOk();
    }

    /** firebase/jwt checks iat/exp against its own clock; keep it on the test's travelled clock. */
    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    private function jwtClock(): void
    {
        JWT::$timestamp = now()->timestamp;
    }

    private function nurse(): array
    {
        $this->jwtClock();

        return $this->bearerFor($this->nutritionist);
    }

    private function me(?Subscriber $s = null): array
    {
        $this->jwtClock();

        return $this->bearerFor(($s ?? $this->patient)->user);
    }

    private function at(string $local): string
    {
        return CarbonImmutable::parse($local, $this->tz)->toIso8601String();
    }

    private function book(string $local, string $type = 'follow_up', ?Subscriber $s = null): TestResponse
    {
        return $this->postJson('/api/v1/me/appointments', ['type' => $type, 'starts_at' => $this->at($local), 'channel' => 'whatsapp', 'topics' => ['weight'], 'note' => 'سؤال عن الخطة'], $this->me($s));
    }

    public function test_slots_follow_windows_days_off_the_12h_lead_and_the_horizon(): void
    {
        $days = collect($this->getJson('/api/v1/me/appointments/slots?type=follow_up', $this->me())->assertOk()->json('days'))->keyBy('date');

        $this->assertFalse($days->has('2026-10-11'), 'today is inside the 12-hour lead');
        $this->assertFalse($days->has('2026-10-12'), 'a day off');
        $this->assertFalse($days->has('2026-10-16'), 'Friday has no window');
        $this->assertSame([$this->at('2026-10-13 09:00'), $this->at('2026-10-13 09:15')], array_slice($days['2026-10-13']['times'], 0, 2));
        $this->assertSame($this->at('2026-10-13 11:30'), last($days['2026-10-13']['times']), 'a 30-minute visit must end by 12:00');
        $this->assertLessThanOrEqual('2026-11-10', $days->keys()->max());

        $quick = collect($this->getJson('/api/v1/me/appointments/slots?type=quick_consult', $this->me())->json('days'))->keyBy('date');
        $this->assertSame($this->at('2026-10-13 11:45'), last($quick['2026-10-13']['times']));
    }

    public function test_windows_must_be_on_15_minute_boundaries(): void
    {
        $this->putJson('/api/v1/me/availability', ['windows' => [['weekday' => 0, 'start' => '09:10', 'end' => '12:00']], 'days_off' => []], $this->nurse())
            ->assertUnprocessable()->assertJsonValidationErrors('windows.0.start');
        $this->putJson('/api/v1/me/availability', ['windows' => [['weekday' => 0, 'start' => '12:00', 'end' => '09:00']], 'days_off' => []], $this->nurse())
            ->assertUnprocessable()->assertJsonPath('code', 'window_invalid');
    }

    public function test_booking_holds_the_time_and_an_overlap_is_refused(): void
    {
        $this->book('2026-10-13 10:00')->assertCreated()->assertJsonPath('status', 'booked')->assertJsonPath('starts_at', $this->at('2026-10-13 10:00'));

        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        // A 15-minute visit at 10:15 overlaps the 30-minute one at 10:00.
        $this->book('2026-10-13 10:15', 'quick_consult', $other)->assertUnprocessable()->assertJsonPath('code', 'slot_unavailable');
        $this->book('2026-10-13 10:30', 'quick_consult', $other)->assertCreated();

        $times = collect($this->getJson('/api/v1/me/appointments/slots?type=follow_up', $this->me($other))->json('days'))->keyBy('date')['2026-10-13']['times'];
        $this->assertNotContains($this->at('2026-10-13 10:00'), $times);
        $this->assertNotContains($this->at('2026-10-13 09:45'), $times, '09:45-10:15 would overlap');
    }

    public function test_the_database_refuses_a_double_booking_even_past_the_checks(): void
    {
        $id = $this->book('2026-10-13 10:00')->json('id');
        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $clash = Appointment::create(['subscriber_id' => $other->id, 'nutritionist_id' => $this->nutritionist->id, 'type' => 'quick_consult', 'channel' => 'phone', 'starts_at' => now(), 'ends_at' => now()]);

        $this->expectException(QueryException::class);
        \DB::table('appointment_holds')->insert(['appointment_id' => $clash->id, 'nutritionist_id' => $this->nutritionist->id,
            'slot_start' => \DB::table('appointment_holds')->where('appointment_id', $id)->value('slot_start')]);
    }

    public function test_one_upcoming_appointment_at_a_time_and_video_needs_a_link(): void
    {
        $this->book('2026-10-13 10:00')->assertCreated();
        $this->book('2026-10-14 10:00')->assertStatus(409)->assertJsonPath('code', 'appointment_exists');

        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->postJson('/api/v1/me/appointments', ['type' => 'follow_up', 'starts_at' => $this->at('2026-10-14 10:00'), 'channel' => 'video'], $this->me($other))
            ->assertUnprocessable()->assertJsonPath('code', 'video_unavailable');
    }

    public function test_the_patient_can_move_or_cancel_until_12_hours_before(): void
    {
        $id = $this->book('2026-10-13 10:00')->json('id');

        $this->patchJson("/api/v1/me/appointments/{$id}", ['starts_at' => $this->at('2026-10-14 09:00')], $this->me())
            ->assertOk()->assertJsonPath('starts_at', $this->at('2026-10-14 09:00'));
        $this->assertSame(2, \DB::table('appointment_holds')->where('appointment_id', $id)->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-13 22:00', $this->tz));
        $this->deleteJson("/api/v1/me/appointments/{$id}", [], $this->me())
            ->assertUnprocessable()->assertJsonPath('code', 'appointment_locked')->assertJsonPath('nutritionist_whatsapp', '+970599000000');
        $this->patchJson("/api/v1/me/appointments/{$id}", ['starts_at' => $this->at('2026-10-15 09:00')], $this->me())->assertJsonPath('code', 'appointment_locked');

        $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00', $this->tz));
        $this->deleteJson("/api/v1/me/appointments/{$id}", [], $this->me())->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled_by', 'patient');
        $this->assertSame(0, \DB::table('appointment_holds')->count());
        $this->assertSame(0, PatientNotification::count(), 'the patient is not told about their own change');
    }

    public function test_the_nutritionist_reschedules_within_availability_and_the_patient_is_told_once(): void
    {
        $id = $this->book('2026-10-13 10:00')->json('id');
        $other = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->book('2026-10-14 09:00', 'follow_up', $other)->assertCreated();

        $this->patchJson("/api/v1/appointments/{$id}", ['starts_at' => $this->at('2026-10-14 09:15')], $this->nurse())->assertUnprocessable()->assertJsonPath('code', 'slot_unavailable');
        $this->patchJson("/api/v1/appointments/{$id}", ['starts_at' => $this->at('2026-10-12 10:00')], $this->nurse())->assertUnprocessable()->assertJsonPath('code', 'slot_unavailable');

        // Inside the patient's 12-hour lead is fine for the nutritionist.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 07:00', $this->tz));
        $this->patchJson("/api/v1/appointments/{$id}", ['starts_at' => $this->at('2026-10-13 11:00')], $this->nurse())->assertOk();

        $this->assertSame(['appointment_rescheduled'], PatientNotification::where('user_id', $this->patient->user_id)->pluck('type')->all());
        $this->assertStringNotContainsString('11:00', json_encode($this->pushes[0]));
    }

    public function test_the_nutritionist_lists_cancels_completes_and_marks_no_show(): void
    {
        $a = $this->book('2026-10-13 10:00')->json('id');
        $this->getJson('/api/v1/appointments?from=2026-10-13&to=2026-10-13', $this->nurse())->assertOk()
            ->assertJsonPath('0.id', $a)->assertJsonPath('0.patient.code', $this->patient->code);

        $this->postJson("/api/v1/appointments/{$a}/complete", [], $this->nurse())->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/v1/appointments/{$a}/cancel", [], $this->nurse())->assertStatus(409)->assertJsonPath('code', 'appointment_closed');

        $b = $this->book('2026-10-14 10:00')->json('id');
        $this->postJson("/api/v1/appointments/{$b}/cancel", ['reason' => 'ظرف طارئ'], $this->nurse())->assertOk()->assertJsonPath('cancel_reason', 'ظرف طارئ');
        $this->assertSame('appointment_cancelled', PatientNotification::latest('id')->value('type'));

        $stranger = User::factory()->nutritionist()->create();
        $this->postJson("/api/v1/appointments/{$b}/no-show", [], $this->bearerFor($stranger))->assertNotFound();
        $this->getJson('/api/v1/me/appointments?scope=past', $this->me())->assertOk()->assertJsonCount(2);
    }

    public function test_ending_follow_up_cancels_future_appointments_and_deletion_removes_them(): void
    {
        $id = $this->book('2026-10-13 10:00')->json('id');

        $this->postJson("/api/v1/clients/{$this->patient->id}/archive", [], $this->nurse())->assertOk();
        $this->assertSame(['cancelled', 'system'], [Appointment::find($id)->status, Appointment::find($id)->cancelled_by]);
        $this->assertSame(1, PatientNotification::where('type', 'appointment_cancelled')->count());
        $this->getJson('/api/v1/me/appointments', $this->me())->assertForbidden()->assertJsonPath('code', 'follow_up_ended');

        $this->deleteJson("/api/v1/clients/{$this->patient->id}", [], $this->nurse())->assertNoContent();
        $this->assertSame(0, Appointment::count());
    }
}
