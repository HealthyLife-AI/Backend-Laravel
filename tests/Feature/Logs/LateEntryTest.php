<?php

namespace Tests\Feature\Logs;

use App\Models\Alert;
use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-19: a patient's meal log or self-reported reading dated more than 7 days
 * back is accepted and marked late, not refused. Only one dated more than 90
 * days back is refused (a wrong device clock). Late entries count like any
 * other in adherence, alerts and progress, and the nutritionist sees the mark.
 */
class LateEntryTest extends TestCase
{
    use AuthenticatesForApi, ExercisesMigrations, RefreshDatabase;

    private User $nutritionist;

    private User $client;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->client = User::factory()->create(['nutritionist_id' => $this->nutritionist->id]);
        $this->client->assignRole('client');
        $this->subscriber = Subscriber::factory()->active()->create([
            'nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id, 'goal' => 'weight_loss',
        ]);
    }

    private function logMeal(string $loggedAt, array $extra = [])
    {
        return $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'meal_type' => 'lunch', 'logged_at' => $loggedAt,
        ] + $extra, $this->bearerFor($this->client));
    }

    private function measure(string $date, float $weight = 80)
    {
        return $this->postJson('/api/v1/me/measurements', ['weight_kg' => $weight, 'recorded_at' => $date], $this->bearerFor($this->client));
    }

    // ---- meal logs --------------------------------------------------------

    public function test_a_log_within_seven_days_is_on_time_and_one_older_is_accepted_as_late(): void
    {
        $this->logMeal(now()->subDays(7)->addHour()->toIso8601String())->assertCreated()->assertJsonPath('is_late', false);
        $this->logMeal(now()->subDays(7)->subHour()->toIso8601String())->assertCreated()->assertJsonPath('is_late', true);
        $this->logMeal(now()->subDays(89)->toIso8601String())->assertCreated()->assertJsonPath('is_late', true);

        $this->assertSame(2, DB::table('meal_logs')->where('is_late', true)->count());
    }

    public function test_a_replayed_offline_entry_older_than_seven_days_is_accepted_and_marked_late(): void
    {
        $body = ['idempotency_key' => (string) Str::uuid()];
        $when = now()->subDays(10)->toIso8601String();

        $first = $this->logMeal($when, $body)->assertCreated()->assertJsonPath('is_late', true);
        $this->logMeal($when, $body)->assertOk()->assertJsonPath('id', $first->json('id'))->assertJsonPath('is_late', true);

        $this->assertSame(1, $this->subscriber->mealLogs()->count());
    }

    public function test_a_log_older_than_ninety_days_is_refused_as_a_wrong_clock(): void
    {
        $this->logMeal(now()->subDays(90)->subHour()->toIso8601String())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'entry_too_old')
            ->assertJsonPath('max_age_days', 90)
            ->assertJsonValidationErrors('logged_at');
        $this->assertDatabaseCount('meal_logs', 0);
    }

    public function test_both_thresholds_come_from_config(): void
    {
        config(['patient_app.late_after_days' => 2, 'patient_app.reject_after_days' => 30]);

        $this->logMeal(now()->subDay()->toIso8601String())->assertCreated()->assertJsonPath('is_late', false);
        $this->logMeal(now()->subDays(3)->toIso8601String())->assertCreated()->assertJsonPath('is_late', true);
        $this->logMeal(now()->subDays(31)->toIso8601String())->assertUnprocessable()->assertJsonPath('max_age_days', 30);
    }

    // ---- self-reported readings --------------------------------------------

    public function test_a_reading_dated_seven_days_back_is_on_time_eight_is_late_and_ninety_one_is_refused(): void
    {
        $this->measure(now()->subDays(7)->toDateString())->assertCreated()->assertJsonPath('is_late', false);
        $this->measure(now()->subDays(8)->toDateString())->assertCreated()->assertJsonPath('is_late', true);
        $this->measure(now()->subDays(90)->toDateString())->assertCreated()->assertJsonPath('is_late', true);

        $this->measure(now()->subDays(91)->toDateString())
            ->assertUnprocessable()->assertJsonPath('code', 'entry_too_old')->assertJsonPath('max_age_days', 90)->assertJsonValidationErrors('recorded_at');
        $this->assertSame(3, $this->subscriber->bodyCompositionReadings()->count());
    }

    public function test_a_clinic_reading_of_any_age_is_never_late(): void
    {
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", [
            'recorded_at' => now()->subYears(2)->toDateString(), 'weight_kg' => 90,
        ], $this->bearerFor($this->nutritionist))->assertCreated()->assertJsonPath('is_late', false);
    }

    // ---- late entries count normally ---------------------------------------

    public function test_a_late_log_counts_in_adherence_like_any_other(): void
    {
        $this->logMeal(now()->subDays(10)->toIso8601String())->assertCreated()->assertJsonPath('is_late', true);
        $window = '?from='.now()->subDays(12)->toDateString().'&to='.now()->subDays(8)->toDateString();

        $this->getJson("/api/v1/me/adherence{$window}", $this->bearerFor($this->client))->assertOk()->assertJsonPath('total_logs', 1);
        $this->getJson("/api/v1/clients/{$this->subscriber->id}/adherence{$window}", $this->bearerFor($this->nutritionist))
            ->assertOk()->assertJsonPath('total_logs', 1);
    }

    public function test_a_late_reading_counts_in_progress_and_alerts_like_any_other(): void
    {
        $this->measure(now()->subDays(10)->toDateString(), 84)->assertCreated()->assertJsonPath('is_late', true);
        $this->measure(now()->toDateString(), 81)->assertCreated()->assertJsonPath('is_late', false);

        $progress = $this->getJson("/api/v1/clients/{$this->subscriber->id}/progress?from=".now()->subDays(30)->toDateString().'&to='.now()->toDateString(), $this->bearerFor($this->nutritionist))->assertOk();
        $this->assertCount(2, $progress->json('weight_trend'));
        $this->assertEquals(-3.0, $progress->json('body_composition.change.weight_kg'));

        // A 3 kg loss inside the 14-day milestone window, starting from the late reading.
        app(AlertEvaluationService::class)->evaluate($this->subscriber->fresh());
        $this->assertTrue($this->subscriber->alerts()->where('type', Alert::TYPE_MILESTONE)->exists());
    }

    // ---- what the nutritionist sees ----------------------------------------

    public function test_the_dashboard_api_marks_late_entries(): void
    {
        $this->measure(now()->subDays(10)->toDateString(), 84)->assertCreated();
        $this->measure(now()->toDateString(), 83)->assertCreated();
        $token = $this->bearerFor($this->nutritionist);

        $progress = $this->getJson("/api/v1/clients/{$this->subscriber->id}/progress?from=".now()->subDays(30)->toDateString().'&to='.now()->toDateString(), $token)->assertOk();
        $this->assertSame([true, false], array_column($progress->json('weight_trend'), 'is_late'));
        $this->assertTrue($progress->json('body_composition.previous.is_late'));
        $this->assertFalse($progress->json('body_composition.latest.is_late'));

        $readings = $this->getJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", $token)->assertOk()->json();
        $this->assertSame([false, true], array_column($readings, 'is_late'), 'newest first');
    }

    // ---- the migration -----------------------------------------------------

    public function test_the_migration_backfills_existing_late_rows_and_rolls_back(): void
    {
        $migration = $this->migrationFile('2026_09_29_150000_add_is_late_to_patient_entries');
        $food = Food::factory()->create();

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('meal_logs', 'is_late'));
            $this->assertFalse(Schema::hasColumn('body_composition_readings', 'is_late'));

            $log = fn (int $daysBefore) => DB::table('meal_logs')->insertGetId([
                'subscriber_id' => $this->subscriber->id, 'food_id' => $food->id, 'quantity_grams' => 100,
                'logged_at' => now()->subDays($daysBefore), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $onTime = $log(3);
            $late = $log(9);
            $reading = fn (int $daysBefore, string $source) => DB::table('body_composition_readings')->insertGetId([
                'subscriber_id' => $this->subscriber->id, 'recorded_at' => now()->subDays($daysBefore)->toDateString(), 'source' => $source,
                'weight_kg' => 80, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $selfOnTime = $reading(7, 'self-reported');
            $selfLate = $reading(8, 'self-reported');
            $clinicOld = $reading(40, 'clinic-analyser');

            $migration->up();

            $isLate = fn (string $table, int $id) => (bool) DB::table($table)->where('id', $id)->value('is_late');
            $this->assertFalse($isLate('meal_logs', $onTime));
            $this->assertTrue($isLate('meal_logs', $late));
            $this->assertFalse($isLate('body_composition_readings', $selfOnTime));
            $this->assertTrue($isLate('body_composition_readings', $selfLate));
            $this->assertFalse($isLate('body_composition_readings', $clinicOld), 'clinic readings are never late');
        } finally {
            if (! Schema::hasColumn('meal_logs', 'is_late')) {
                $migration->up();
            }
        }
    }
}
