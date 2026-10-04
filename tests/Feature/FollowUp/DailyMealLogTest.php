<?php

namespace Tests\Feature\FollowUp;

use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * The nutritionist's day-by-day meal log, and that a log's kind (planned /
 * alternative / off_plan) is fixed when it is made — a later plan edit that
 * swaps a planned item with its alternative changes neither the badge nor
 * that day's adherence.
 */
class DailyMealLogTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    private Food $rice;

    private Food $bulgur;

    private Food $falafel;

    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->rice = Food::factory()->create(['name_en' => 'Rice', 'calories_per_100g' => 130]);
        $this->bulgur = Food::factory()->create(['name_en' => 'Bulgur', 'calories_per_100g' => 120]);
        $this->falafel = Food::factory()->create(['name_en' => 'Falafel', 'calories_per_100g' => 330]);

        $this->planId = $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans", ['meals' => [
            ['name' => 'lunch', 'items' => [['food_id' => $this->rice->id, 'quantity_grams' => 200, 'alternatives' => [['food_id' => $this->bulgur->id, 'quantity_grams' => 200]]]]],
        ]], $this->nurse())->assertCreated()->json('id');
        $this->postJson("/api/v1/clients/{$this->patient->id}/meal-plans/{$this->planId}/activate", [], $this->nurse())->assertOk();
    }

    private function nurse(): array
    {
        return $this->bearerFor($this->nutritionist);
    }

    private function plan(): array
    {
        return $this->getJson('/api/v1/me/meal-plan', $this->bearerFor($this->patient->user))->assertOk()->json();
    }

    private function logAs(int $foodId, ?int $itemId, string $at): void
    {
        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $foodId, 'meal_item_id' => $itemId, 'quantity_grams' => 200,
            'logged_at' => $at, 'idempotency_key' => (string) Str::uuid(),
        ], $this->bearerFor($this->patient->user))->assertCreated();
    }

    private function daily(string $query = ''): TestResponse
    {
        return $this->getJson("/api/v1/clients/{$this->patient->id}/meal-logs/daily{$query}", $this->nurse());
    }

    public function test_each_log_carries_its_kind_and_the_marks(): void
    {
        $plan = $this->plan();
        $planned = $plan['meals'][0]['items'][0];
        $alternative = $planned['alternatives'][0];

        $this->logAs($this->rice->id, $planned['id'], now()->subHours(3)->toIso8601String());
        $this->logAs($this->bulgur->id, $alternative['id'], now()->subHours(2)->toIso8601String());
        $this->logAs($this->falafel->id, null, now()->subHour()->toIso8601String());
        $this->logAs($this->falafel->id, null, now()->subDays(9)->toIso8601String()); // late

        $today = $this->daily()->assertOk()->json('days.0');
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertSame(['planned', 'alternative', 'off_plan'], array_column($today['logs'], 'log_kind'));
        $this->assertSame([true, true, false], array_column($today['logs'], 'is_on_plan'));

        $late = $this->daily('?from='.now()->subDays(9)->toDateString().'&to='.now()->subDays(9)->toDateString())->json('days.0.logs.0');
        $this->assertTrue($late['is_late']);
        $this->assertSame('off_plan', $late['log_kind']);
    }

    public function test_swapping_planned_and_alternative_changes_neither_the_badge_nor_the_days_adherence(): void
    {
        $planned = $this->plan()['meals'][0]['items'][0];
        $this->logAs($this->rice->id, $planned['id'], now()->subHours(2)->toIso8601String());
        $this->logAs($this->falafel->id, null, now()->subHour()->toIso8601String());

        $before = app(AdherenceService::class)->summary($this->patient->fresh(), now()->toDateString(), now()->toDateString());
        $this->assertSame(50.0, $before['adherence_percent']);

        // «تعيين كافتراضي»: bulgur becomes the planned item, rice its alternative.
        // reconcileItems() deletes and recreates both rows, nulling the old link.
        $this->putJson("/api/v1/clients/{$this->patient->id}/meal-plans/{$this->planId}", ['meals' => [
            ['name' => 'lunch', 'items' => [['food_id' => $this->bulgur->id, 'quantity_grams' => 200, 'alternatives' => [['food_id' => $this->rice->id, 'quantity_grams' => 200]]]]],
        ]], $this->nurse())->assertOk();

        $riceLog = MealLog::where('food_id', $this->rice->id)->first();
        $this->assertNull($riceLog->meal_item_id, 'the plan edit really did break the link');
        $this->assertSame('planned', $riceLog->log_kind);

        $this->assertSame('planned', $this->daily()->json('days.0.logs.0.log_kind'));
        $after = app(AdherenceService::class)->summary($this->patient->fresh(), now()->toDateString(), now()->toDateString());
        $this->assertSame($before['adherence_percent'], $after['adherence_percent']);
    }

    public function test_every_day_in_the_range_is_listed_with_the_same_totals_as_the_chart(): void
    {
        $planned = $this->plan()['meals'][0]['items'][0];
        $this->logAs($this->rice->id, $planned['id'], now()->subDay()->setTime(13, 0)->toIso8601String());

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();
        $days = $this->daily("?from={$from}&to={$to}")->assertOk()->json('days');

        $this->assertSame([$to, now()->subDay()->toDateString(), $from], array_column($days, 'date'));
        $this->assertSame([], $days[0]['logs']);
        $chart = collect(app(AdherenceService::class)->dailyCalories($this->patient, $from, $to))->keyBy('date');
        foreach ($days as $day) {
            $this->assertEquals($chart[$day['date']]['logged_calories'], $day['logged_calories']);
            $this->assertEquals($chart[$day['date']]['planned_calories'], $day['planned_calories']);
        }
    }

    public function test_the_range_is_capped_at_31_days_and_defaults_to_7(): void
    {
        $this->daily()->assertOk()->assertJsonCount(7, 'days');
        $this->daily('?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString())->assertOk()->assertJsonCount(31, 'days');
        $this->daily('?from='.now()->subDays(31)->toDateString().'&to='.now()->toDateString())
            ->assertUnprocessable()->assertJsonPath('code', 'range_too_large')->assertJsonPath('max_days', 31);
    }

    public function test_another_nutritionist_and_the_patient_cannot_read_it(): void
    {
        $other = User::factory()->nutritionist()->create();
        $this->getJson("/api/v1/clients/{$this->patient->id}/meal-logs/daily", $this->bearerFor($other))->assertNotFound();
        $this->getJson("/api/v1/clients/{$this->patient->id}/meal-logs/daily", $this->bearerFor($this->patient->user))->assertForbidden();
    }

    public function test_existing_rows_are_backfilled_from_their_current_link(): void
    {
        $migration = require database_path('migrations/2026_10_05_100000_add_log_kind_to_meal_logs_table.php');
        $planned = $this->plan()['meals'][0]['items'][0];

        try {
            $migration->down();
            \DB::table('meal_logs')->insert([
                ['subscriber_id' => $this->patient->id, 'food_id' => $this->rice->id, 'meal_item_id' => $planned['id'], 'quantity_grams' => 100, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['subscriber_id' => $this->patient->id, 'food_id' => $this->bulgur->id, 'meal_item_id' => $planned['alternatives'][0]['id'], 'quantity_grams' => 100, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['subscriber_id' => $this->patient->id, 'food_id' => $this->falafel->id, 'meal_item_id' => null, 'quantity_grams' => 100, 'logged_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);
        } finally {
            $migration->up();
        }

        $this->assertSame(['planned', 'alternative', 'off_plan'], MealLog::orderBy('id')->pluck('log_kind')->all());
    }
}
