<?php

namespace Tests\Feature\Logs;

use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** B4: a reading's "today" is the clinic's day (SCHEDULE_TIMEZONE), not UTC's. */
class ReadingDayBoundaryTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['scheduling.timezone' => 'Asia/Gaza']);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    /** 00:30 Gaza on 8 October = 21:30 UTC on 7 October. */
    private function atLocal(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-10-08 {$time}", 'Asia/Gaza'));
        JWT::$timestamp = now()->timestamp;
    }

    public function test_a_reading_dated_today_is_accepted_between_midnight_and_three_local(): void
    {
        foreach (['00:30', '02:59'] as $i => $time) {
            $this->atLocal($time);
            $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80 + $i, 'recorded_at' => '2026-10-08'], $this->bearerFor($this->patient->user))
                ->assertSuccessful()->assertJsonPath('recorded_at', '2026-10-08');
        }

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => '2026-10-09'], $this->bearerFor($this->patient->user))
            ->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    }

    public function test_without_a_date_the_reading_gets_the_local_date(): void
    {
        $this->atLocal('00:30');

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80], $this->bearerFor($this->patient->user))
            ->assertCreated()->assertJsonPath('recorded_at', '2026-10-08');
    }

    public function test_the_nutritionist_can_enter_todays_clinic_reading_after_midnight(): void
    {
        $this->atLocal('01:15');

        $this->postJson("/api/v1/clients/{$this->patient->id}/body-composition-readings", ['recorded_at' => '2026-10-08', 'weight_kg' => 81], $this->bearerFor($this->nutritionist))
            ->assertCreated();
    }

    public function test_late_and_too_old_count_local_days(): void
    {
        $this->atLocal('00:30');
        config(['patient_app.late_after_days' => 7, 'patient_app.reject_after_days' => 90]);

        // Today minus 7 local days is on time; one day earlier is late.
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => '2026-10-01'], $this->bearerFor($this->patient->user))
            ->assertCreated()->assertJsonPath('is_late', false);
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => '2026-09-30'], $this->bearerFor($this->patient->user))
            ->assertCreated()->assertJsonPath('is_late', true);
        // 90 local days back is accepted, 91 refused.
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => '2026-07-10'], $this->bearerFor($this->patient->user))->assertCreated();
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => '2026-07-09'], $this->bearerFor($this->patient->user))
            ->assertUnprocessable()->assertJsonPath('code', 'entry_too_old');
    }
}
