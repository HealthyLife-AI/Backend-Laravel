<?php

namespace Tests\Feature\Progress;

use App\Models\Alert;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertEvaluationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * Part C: "days" are the clinic's (SCHEDULE_TIMEZONE = Asia/Gaza here, UTC+3 in
 * October), not UTC's. A meal at 01:30 Gaza time is on THAT local day although
 * its UTC date is the day before. Tested at 00:30, 02:59 and 03:01 local.
 */
class ClinicDayBoundaryTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private User $nutritionist;

    private Subscriber $patient;

    private Food $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['scheduling.timezone' => 'Asia/Gaza']);
        $this->nutritionist = User::factory()->nutritionist()->create();
        $this->patient = Subscriber::factory()->active()->forNutritionist($this->nutritionist)->create();
        $this->food = Food::factory()->create(['calories_per_100g' => 200, 'status' => 'approved']);
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        parent::tearDown();
    }

    private function logAtLocal(string $local, int $grams = 100, ?int $itemId = null): MealLog
    {
        return MealLog::create([
            'subscriber_id' => $this->patient->id, 'food_id' => $this->food->id, 'meal_item_id' => $itemId,
            'quantity_grams' => $grams, 'logged_at' => CarbonImmutable::parse($local, 'Asia/Gaza')->utc(),
        ]);
    }

    private function nurse(): array
    {
        JWT::$timestamp = now()->timestamp;

        return $this->bearerFor($this->nutritionist);
    }

    private function me(): array
    {
        JWT::$timestamp = now()->timestamp;

        return $this->bearerFor($this->patient->user);
    }

    /** @return array<int, array{string, string}> */
    public static function boundaryTimes(): array
    {
        return [['00:30'], ['02:59'], ['03:01']];
    }

    #[DataProvider('boundaryTimes')]
    public function test_a_meal_after_midnight_local_is_on_the_local_day_in_every_view(string $time): void
    {
        // "Now" is 12:00 local on 8 Oct: the meal at 01:30 local on the 8th is today's.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Gaza'));
        $this->logAtLocal("2026-10-08 {$time}", 150); // 300 kcal
        $this->logAtLocal('2026-10-07 23:30', 100);   // 200 kcal, yesterday local (20:30 UTC the same day)

        // The nutritionist's daily view and the progress chart.
        $days = collect($this->getJson("/api/v1/clients/{$this->patient->id}/meal-logs/daily?from=2026-10-07&to=2026-10-08", $this->nurse())->assertOk()->json('days'))->keyBy('date');
        $this->assertCount(1, $days['2026-10-08']['logs']);
        $this->assertEquals(300, $days['2026-10-08']['logged_calories']);
        $this->assertCount(1, $days['2026-10-07']['logs']);
        $this->assertEquals(200, $days['2026-10-07']['logged_calories']);

        $chart = collect($this->getJson("/api/v1/clients/{$this->patient->id}/progress?from=2026-10-07&to=2026-10-08", $this->nurse())->assertOk()->json('daily_calories'))->keyBy('date');
        $this->assertEquals(300, $chart['2026-10-08']['logged_calories']);
        $this->assertEquals(200, $chart['2026-10-07']['logged_calories']);

        // The patient's own list filtered by local date.
        $mine = $this->getJson('/api/v1/me/meal-logs?from=2026-10-08&to=2026-10-08', $this->me())->assertOk()->json('data');
        $this->assertCount(1, $mine);
    }

    public function test_the_default_window_and_adherence_end_on_the_clinics_today(): void
    {
        // 00:30 local on the 8th is still the 7th in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30', 'Asia/Gaza'));
        $this->logAtLocal('2026-10-08 00:10');

        $daily = $this->getJson("/api/v1/clients/{$this->patient->id}/meal-logs/daily", $this->nurse())->assertOk();
        $this->assertSame('2026-10-08', $daily->json('to'));
        $this->assertCount(1, $daily->json('days.0.logs'));

        $adherence = $this->getJson("/api/v1/clients/{$this->patient->id}/adherence", $this->nurse())->assertOk();
        $this->assertSame('2026-10-08', $adherence->json('to'));
        $this->assertSame(1, $adherence->json('total_logs'));
    }

    public function test_a_plan_activated_after_midnight_local_applies_from_that_local_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Gaza'));
        // Activated 01:00 local on the 8th = 22:00 UTC on the 7th.
        $planId = DB::table('meal_plans')->insertGetId(['subscriber_id' => $this->patient->id, 'created_by' => $this->nutritionist->id, 'status' => 'active', 'activated_at' => CarbonImmutable::parse('2026-10-08 01:00', 'Asia/Gaza')->utc(), 'created_at' => now(), 'updated_at' => now()]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        MealItem::create(['meal_id' => $mealId, 'food_id' => $this->food->id, 'quantity_grams' => 500, 'sort_order' => 0]);

        $chart = collect($this->getJson("/api/v1/clients/{$this->patient->id}/progress?from=2026-10-07&to=2026-10-08", $this->nurse())->assertOk()->json('daily_calories'))->keyBy('date');

        $this->assertNull($chart['2026-10-07']['planned_calories'], 'the plan did not exist yet on the 7th (local)');
        $this->assertEquals(1000, $chart['2026-10-08']['planned_calories']);
    }

    public function test_the_calorie_streak_counts_clinic_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30', 'Asia/Gaza'));
        HealthProfile::query()->updateOrCreate(['subscriber_id' => $this->patient->id], ['weight_kg' => 80, 'height_cm' => 175, 'age' => 35, 'gender' => 'male', 'activity_level' => 'light', 'daily_calorie_needs' => 500]);
        config(['alerts.calorie_exceeded_streak_days' => 3]);

        // Three local days (5th, 6th, 7th), each 600 kcal > 500. The late-evening meal of the 7th
        // (23:40 local = 20:40 UTC) and an early one of the 6th (00:20 local = 21:20 UTC the 5th) pin the cut.
        foreach (['2026-10-05 08:00', '2026-10-06 00:20', '2026-10-06 09:00', '2026-10-07 12:00', '2026-10-07 23:40'] as $at) {
            $this->logAtLocal($at, 150); // 300 kcal each
        }
        $this->logAtLocal('2026-10-05 20:00', 150);

        app(AlertEvaluationService::class)->evaluate($this->patient->fresh());

        $this->assertTrue(Alert::where('subscriber_id', $this->patient->id)->where('type', Alert::TYPE_CALORIES_EXCEEDED)->whereNull('resolved_at')->exists());
    }

    public function test_the_overview_counts_not_logged_today_by_the_clinics_day(): void
    {
        // 00:30 local on the 8th: a meal at 23:50 local on the 7th is NOT "today".
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30', 'Asia/Gaza'));
        $this->patient->forceFill(['last_logged_at' => CarbonImmutable::parse('2026-10-07 23:50', 'Asia/Gaza')->utc()])->save();

        $this->getJson('/api/v1/dashboard/overview', $this->nurse())->assertOk()->assertJsonPath('not_logged_today', 1);

        $this->patient->forceFill(['last_logged_at' => CarbonImmutable::parse('2026-10-08 00:10', 'Asia/Gaza')->utc()])->save();
        $this->getJson('/api/v1/dashboard/overview', $this->nurse())->assertOk()->assertJsonPath('not_logged_today', 0);
    }
}
