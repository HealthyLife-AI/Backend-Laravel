<?php

namespace Tests\Feature\Logs;

use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\ExercisesMigrations;
use Tests\TestCase;

/**
 * BR-15: the patient can edit or delete their own entries for 7 days, and
 * every edit is recorded in `edited_at` (not updated_at) so the
 * nutritionist can see the entry was changed ("معدّلة").
 */
class EditMarkerTest extends TestCase
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
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $this->client->id]);
    }

    private function log(int $daysAgo): MealLog
    {
        return MealLog::create([
            'subscriber_id' => $this->subscriber->id, 'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 100, 'meal_type' => 'snack', 'logged_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_a_log_can_be_edited_on_day_six_and_is_refused_on_day_eight(): void
    {
        $daySix = $this->log(6);
        $dayEight = $this->log(8);
        $token = $this->bearerFor($this->client);

        $this->patchJson("/api/v1/me/meal-logs/{$daySix->id}", ['quantity_grams' => 150], $token)->assertOk();
        $this->deleteJson("/api/v1/me/meal-logs/{$daySix->id}", [], $token)->assertNoContent();

        $this->patchJson("/api/v1/me/meal-logs/{$dayEight->id}", ['quantity_grams' => 150], $token)
            ->assertForbidden()->assertJsonPath('code', 'log_locked')->assertJsonPath('editable_days', 7);
        $this->deleteJson("/api/v1/me/meal-logs/{$dayEight->id}", [], $token)->assertForbidden()->assertJsonPath('code', 'log_locked');
        $this->assertSame('100.0', $dayEight->fresh()->quantity_grams);
    }

    public function test_editable_until_and_deletable_until_follow_the_seven_day_window(): void
    {
        $log = $this->log(2);
        $token = $this->bearerFor($this->client);
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => now()->subDays(2)->toDateString()], $token)->assertCreated();

        $this->assertSame($log->logged_at->addDays(7)->toIso8601String(), $this->getJson('/api/v1/me/meal-logs', $token)->json('data.0.editable_until'));
        $this->assertSame(now()->subDays(2)->startOfDay()->addDays(7)->toIso8601String(), $this->getJson('/api/v1/me/measurements', $token)->json('0.deletable_until'));
    }

    public function test_an_edit_records_edited_at_and_a_log_never_edited_has_none(): void
    {
        $log = $this->log(1);
        $untouched = $this->log(1);
        $token = $this->bearerFor($this->client);

        $this->assertNull($this->getJson('/api/v1/me/meal-logs', $token)->json('data.0.edited_at'));

        $edited = $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 150], $token)->assertOk();

        $this->assertNotNull($edited->json('edited_at'));
        $this->assertNotNull($log->fresh()->edited_at);
        $this->assertNull($untouched->fresh()->edited_at);
    }

    public function test_sending_the_same_values_again_is_not_an_edit(): void
    {
        $log = $this->log(1);

        $this->patchJson("/api/v1/me/meal-logs/{$log->id}", ['quantity_grams' => 100, 'meal_type' => 'snack'], $this->bearerFor($this->client))
            ->assertOk()->assertJsonPath('edited_at', null);
    }

    public function test_edited_at_is_its_own_column_not_updated_at(): void
    {
        $log = $this->log(1);

        // Something other than the patient touching the row: updated_at moves, edited_at doesn't.
        $log->forceFill(['meal_item_id' => null, 'quantity_grams' => 101])->save();

        $this->assertNull($log->fresh()->edited_at);
    }

    public function test_re_sending_a_reading_with_new_figures_marks_it_edited_and_the_same_figures_do_not(): void
    {
        $token = $this->bearerFor($this->client);
        $date = now()->subDays(3)->toDateString();

        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => $date], $token)->assertCreated()->assertJsonPath('edited_at', null);
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 80, 'recorded_at' => $date], $token)->assertOk()->assertJsonPath('edited_at', null);
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 79.4, 'recorded_at' => $date], $token)->assertOk()
            ->assertJsonPath('edited_at', fn ($v) => $v !== null);
    }

    public function test_the_dashboard_api_carries_the_edited_and_late_markers(): void
    {
        $token = $this->bearerFor($this->client);
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 85, 'recorded_at' => now()->subDays(10)->toDateString()], $token)->assertCreated();
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 83, 'recorded_at' => now()->subDays(2)->toDateString()], $token)->assertCreated();
        $this->postJson('/api/v1/me/measurements', ['weight_kg' => 82.5, 'recorded_at' => now()->subDays(2)->toDateString()], $token)->assertOk();

        $nutritionist = $this->bearerFor($this->nutritionist);
        $progress = $this->getJson("/api/v1/clients/{$this->subscriber->id}/progress?from=".now()->subDays(30)->toDateString().'&to='.now()->toDateString(), $nutritionist)->assertOk();

        $trend = $progress->json('weight_trend');
        $this->assertSame([true, false], array_column($trend, 'is_late'));
        $this->assertNull($trend[0]['edited_at']);
        $this->assertNotNull($trend[1]['edited_at']);
        $this->assertNotNull($progress->json('body_composition.latest.edited_at'));
        $this->assertTrue($progress->json('body_composition.previous.is_late'));

        $readings = $this->getJson("/api/v1/clients/{$this->subscriber->id}/body-composition-readings", $nutritionist)->assertOk()->json();
        $this->assertNotNull($readings[0]['edited_at']);
        $this->assertFalse($readings[0]['is_late']);
        $this->assertNull($readings[1]['edited_at']);
        $this->assertTrue($readings[1]['is_late']);
    }

    public function test_the_migration_adds_and_drops_edited_at(): void
    {
        $migration = $this->migrationFile('2026_09_29_160000_add_edited_at_to_patient_entries');
        $this->log(1);

        $migration->down();
        try {
            $this->assertFalse(Schema::hasColumn('meal_logs', 'edited_at'));
            $this->assertFalse(Schema::hasColumn('body_composition_readings', 'edited_at'));
            $migration->up();
            $this->assertNull(DB::table('meal_logs')->value('edited_at'), 'existing rows are not marked edited');
        } finally {
            if (! Schema::hasColumn('meal_logs', 'edited_at')) {
                $migration->up();
            }
        }
    }
}
