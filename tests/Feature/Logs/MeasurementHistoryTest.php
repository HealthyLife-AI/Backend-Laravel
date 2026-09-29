<?php

namespace Tests\Feature\Logs;

use App\Models\BodyCompositionReading;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The patient's own measurement history: GET /me/measurements (everything,
 * oldest first), DELETE /me/measurements/{id} for their own self-reported
 * readings inside the BR-15 window, and the BR-19 backdating limit on POST.
 */
class MeasurementHistoryTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        [$this->client, $this->subscriber] = $this->makePatient();
    }

    /** @return array{0: User, 1: Subscriber} */
    private function makePatient(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');

        return [$client, Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id])];
    }

    private function auth(?User $as = null): array
    {
        return $this->bearerFor($as ?? $this->client);
    }

    private function reading(string $date, string $source = BodyCompositionReading::SOURCE_SELF, array $extra = [], ?Subscriber $for = null): BodyCompositionReading
    {
        return ($for ?? $this->subscriber)->bodyCompositionReadings()->create($extra + [
            'recorded_at' => $date,
            'source' => $source,
            'weight_kg' => 80,
        ]);
    }

    // ---- GET --------------------------------------------------------------

    public function test_the_history_lists_every_reading_oldest_first_with_all_fields_and_source(): void
    {
        $clinic = $this->reading(now()->subDays(20)->toDateString(), BodyCompositionReading::SOURCE_CLINIC, ['weight_kg' => 82, 'body_fat_percent' => 24.5, 'muscle_mass_kg' => 33, 'water_percent' => 51, 'waist_cm' => 90]);
        $newest = $this->reading(now()->toDateString(), extra: ['weight_kg' => 79.5, 'hip_cm' => 100]);
        $middle = $this->reading(now()->subDays(3)->toDateString(), extra: ['weight_kg' => 80.5]);

        $rows = $this->getJson('/api/v1/me/measurements', $this->auth())->assertOk()->json();

        $this->assertSame([$clinic->id, $middle->id, $newest->id], array_column($rows, 'id'));
        $this->assertSame('clinic-analyser', $rows[0]['source']);
        $this->assertSame('self-reported', $rows[1]['source']);
        $this->assertEquals(24.5, $rows[0]['body_fat_percent']);
        $this->assertEquals(100, $rows[2]['hip_cm']);
        foreach (['recorded_at', 'weight_kg', 'body_fat_percent', 'muscle_mass_kg', 'water_percent', 'waist_cm', 'hip_cm', 'thigh_cm', 'arm_cm'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
    }

    public function test_the_history_says_which_readings_the_patient_may_still_delete(): void
    {
        $this->reading(now()->subDays(5)->toDateString(), BodyCompositionReading::SOURCE_CLINIC);
        $mine = $this->reading(now()->toDateString());

        $rows = $this->getJson('/api/v1/me/measurements', $this->auth())->assertOk()->json();

        $this->assertNull($rows[0]['deletable_until'], 'a clinic reading is never deletable');
        $this->assertSame(now()->startOfDay()->addDays(7)->toIso8601String(), $rows[1]['deletable_until']);
        $this->assertSame($mine->id, $rows[1]['id']);
    }

    public function test_the_history_can_be_narrowed_to_a_window_and_a_half_open_range_is_refused(): void
    {
        $this->reading(now()->subDays(20)->toDateString());
        $inside = $this->reading(now()->subDays(2)->toDateString());

        $rows = $this->getJson('/api/v1/me/measurements?from='.now()->subDays(5)->toDateString().'&to='.now()->toDateString(), $this->auth())->assertOk()->json();
        $this->assertSame([$inside->id], array_column($rows, 'id'));

        $this->getJson('/api/v1/me/measurements?from='.now()->toDateString(), $this->auth())->assertUnprocessable();
    }

    public function test_the_history_holds_only_the_callers_own_readings(): void
    {
        [, $other] = $this->makePatient();
        $this->reading(now()->toDateString(), extra: [], for: $other);

        $this->getJson('/api/v1/me/measurements', $this->auth())->assertOk()->assertExactJson([]);
    }

    public function test_a_nutritionist_and_an_archived_patient_cannot_read_it(): void
    {
        $token = $this->auth();
        $this->getJson('/api/v1/me/measurements', $this->auth(User::find($this->subscriber->nutritionist_id)))->assertForbidden();

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->getJson('/api/v1/me/measurements', $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
    }

    // ---- DELETE -----------------------------------------------------------

    public function test_a_patient_deletes_their_own_self_reported_reading(): void
    {
        $reading = $this->reading(now()->toDateString());

        $this->deleteJson("/api/v1/me/measurements/{$reading->id}", [], $this->auth())->assertNoContent();
        $this->assertDatabaseMissing('body_composition_readings', ['id' => $reading->id]);

        $this->deleteJson("/api/v1/me/measurements/{$reading->id}", [], $this->auth())->assertNotFound();
    }

    public function test_a_clinic_reading_cannot_be_deleted_by_the_patient(): void
    {
        $clinic = $this->reading(now()->toDateString(), BodyCompositionReading::SOURCE_CLINIC);

        $this->deleteJson("/api/v1/me/measurements/{$clinic->id}", [], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'reading_not_deletable');
        $this->assertDatabaseHas('body_composition_readings', ['id' => $clinic->id]);
    }

    public function test_the_delete_window_runs_from_the_readings_date(): void
    {
        // 7 days from the START of the reading's date: one dated 6 days ago is
        // deletable until tomorrow, one dated 7 days ago locked at midnight.
        $sixDaysAgo = $this->reading(now()->subDays(6)->toDateString());
        $sevenDaysAgo = $this->reading(now()->subDays(7)->toDateString());

        $this->deleteJson("/api/v1/me/measurements/{$sevenDaysAgo->id}", [], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked')->assertJsonPath('editable_days', 7);
        $this->assertDatabaseHas('body_composition_readings', ['id' => $sevenDaysAgo->id]);

        $this->deleteJson("/api/v1/me/measurements/{$sixDaysAgo->id}", [], $this->auth())->assertNoContent();
    }

    public function test_another_patients_reading_is_a_404(): void
    {
        [$other, $otherSubscriber] = $this->makePatient();
        $theirs = $this->reading(now()->toDateString(), extra: [], for: $otherSubscriber);

        $this->deleteJson("/api/v1/me/measurements/{$theirs->id}", [], $this->auth())->assertNotFound();
        $this->assertDatabaseHas('body_composition_readings', ['id' => $theirs->id]);
        $this->deleteJson("/api/v1/me/measurements/{$theirs->id}", [], $this->auth($other))->assertNoContent();
    }

    public function test_a_nutritionist_and_an_archived_patient_cannot_delete(): void
    {
        $reading = $this->reading(now()->toDateString());
        $token = $this->auth();

        $this->deleteJson("/api/v1/me/measurements/{$reading->id}", [], $this->auth(User::find($this->subscriber->nutritionist_id)))->assertForbidden();

        $this->subscriber->forceFill(['archived_at' => now()])->save();
        $this->deleteJson("/api/v1/me/measurements/{$reading->id}", [], $token)->assertForbidden()->assertJsonPath('code', 'follow_up_ended');
        $this->assertDatabaseHas('body_composition_readings', ['id' => $reading->id]);
    }

    // ---- POST: BR-19 and BR-15 on a re-sent day ----------------------------

    public function test_re_sending_a_saved_day_after_its_window_is_a_replay_not_an_edit(): void
    {
        $old = $this->reading(now()->subDays(8)->toDateString(), extra: ['weight_kg' => 81.2]);

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 81.2, 'recorded_at' => $old->recorded_at->toDateString()], $this->auth())
            ->assertOk()->assertJsonPath('id', $old->id);

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 70, 'recorded_at' => $old->recorded_at->toDateString()], $this->auth())
            ->assertForbidden()->assertJsonPath('code', 'log_locked');
        $this->assertEquals(81.2, $old->fresh()->weight_kg);
    }

    public function test_a_day_still_inside_the_window_can_be_corrected(): void
    {
        $recent = $this->reading(now()->subDay()->toDateString(), extra: ['weight_kg' => 81]);

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80.4, 'recorded_at' => $recent->recorded_at->toDateString()], $this->auth())->assertOk();
        $this->assertEquals(80.4, $recent->fresh()->weight_kg);
    }
}
