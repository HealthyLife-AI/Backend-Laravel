<?php

namespace Tests\Feature\Audit\CrossApp;

use App\Models\Appointment;
use App\Models\Food;
use App\Models\MealItem;
use App\Models\NotificationPreference;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Appointments\AppointmentService;
use App\Services\Scheduling\SelfScheduler;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesForApi;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/**
 * X-01 (I5): "today" must be the PATIENT's own day. The backend cuts every day
 * at one SCHEDULE_TIMEZONE (App\Support\ClinicDay); the Flutter app groups by
 * device-local day (daily_log_screen.dart / meal_log_cubit.dart isSameLocalDay).
 *
 * Fixed instant 2026-11-20 21:30 UTC (winter, Gaza = UTC+2, Yemen = UTC+3):
 *   - Gaza patient eats dinner at 23:30 local on Fri 20 Nov;
 *   - Yemen patient eats a snack at 00:30 local on Sat 21 Nov.
 * Both are the same UTC instant, so ANY single SCHEDULE_TIMEZONE puts one of
 * them on the wrong day. Each test asserts the patient's own-day expectation;
 * the failing ones are the bug. Run on MySQL (phpunit.crossapp.xml).
 */
class PatientDayBoundaryTest extends TestCase
{
    use AuthenticatesForApi, FakesPush, RefreshDatabase;

    private const MEAL_UTC = '2026-11-20 21:30:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();
    }

    /** firebase/php-jwt checks exp against time(), not Carbon: align it with the fake clock. */
    private function bearer(User $user): array
    {
        JWT::$timestamp = CarbonImmutable::now()->timestamp;

        return $this->bearerFor($user);
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Subscriber, 2: MealItem, 3: User} */
    private function patient(string $deviceTz): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id, 'fcm_token' => 'tok-'.Str::random(6)]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->active()->create([
            'nutritionist_id' => $nutritionist->id, 'user_id' => $client->id,
            'created_at' => '2026-11-01 08:00:00', 'activated_at' => '2026-11-01 08:00:00',
        ]);
        // The app already syncs the device's IANA zone here (notification_preferences_sync.dart).
        NotificationPreference::for($client)->fill(['timezone' => $deviceTz])->save();

        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id, 'status' => 'active',
            'activated_at' => '2026-11-01 08:00:00', 'created_at' => '2026-11-01 08:00:00', 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId, 'name' => 'dinner', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $food = Food::factory()->create(['calories_per_100g' => 200]);
        $item = MealItem::create(['meal_id' => $mealId, 'food_id' => $food->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        return [$client, $subscriber, $item, $nutritionist];
    }

    private function logAt(User $client, MealItem $item, string $isoWithOffset): void
    {
        $this->postJson('/api/v1/me/meal-logs', [
            'food_id' => $item->food_id, 'meal_item_id' => $item->id, 'quantity_grams' => 100,
            'idempotency_key' => (string) Str::uuid(), 'logged_at' => $isoWithOffset,
        ], $this->bearer($client))->assertCreated();
    }

    /** Day the nutritionist's dashboard files the log under (GET clients/{id}/meal-logs/daily). */
    private function dashboardDayOfOnlyLog(User $nutritionist, Subscriber $s): ?string
    {
        $days = $this->getJson("/api/v1/clients/{$s->id}/meal-logs/daily?from=2026-11-19&to=2026-11-22", $this->bearer($nutritionist))
            ->assertOk()->json('days');
        foreach ($days as $d) {
            if (count($d['logs']) > 0) {
                return $d['date'];
            }
        }

        return null;
    }

    public static function scenarios(): array
    {
        return [
            // tz, device tz, local iso, patient's own day
            'Riyadh server / Gaza patient 23:30' => ['Asia/Riyadh', 'Asia/Gaza', '2026-11-20T23:30:00+02:00', '2026-11-20'],
            'Riyadh server / Yemen patient 00:30' => ['Asia/Riyadh', 'Asia/Aden', '2026-11-21T00:30:00+03:00', '2026-11-21'],
            'Gaza server / Gaza patient 23:30' => ['Asia/Gaza', 'Asia/Gaza', '2026-11-20T23:30:00+02:00', '2026-11-20'],
            'Gaza server / Yemen patient 00:30' => ['Asia/Gaza', 'Asia/Aden', '2026-11-21T00:30:00+03:00', '2026-11-21'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_dashboard_and_patient_list_file_the_meal_on_the_patients_own_day(string $serverTz, string $deviceTz, string $localIso, string $ownDay): void
    {
        config(['scheduling.timezone' => $serverTz]);
        CarbonImmutable::setTestNow('2026-11-21 10:00:00');
        [$client, $s, $item, $nutritionist] = $this->patient($deviceTz);
        $this->logAt($client, $item, $localIso);

        // What the patient's app asks for "my day" (GET me/meal-logs?from=day&to=day).
        $mine = $this->getJson("/api/v1/me/meal-logs?from={$ownDay}&to={$ownDay}", $this->bearer($client))->assertOk()->json('data');

        $this->assertSame([
            'dashboard_day' => $ownDay,
            'patient_list_for_own_day_count' => 1,
        ], [
            'dashboard_day' => $this->dashboardDayOfOnlyLog($nutritionist, $s),
            'patient_list_for_own_day_count' => count($mine),
        ], "server tz {$serverTz}, patient in {$deviceTz} ate at {$localIso}");
    }

    /**
     * 20:00 reminder: the Gaza patient's 23:30 dinner yesterday is "today" for a
     * Riyadh server (00:30 Riyadh), so the next evening they get no reminder
     * although they have logged nothing on their own day.
     */
    public function test_reminder_reaches_gaza_patient_who_logged_nothing_on_their_own_day_riyadh_server(): void
    {
        config(['scheduling.timezone' => 'Asia/Riyadh']);
        CarbonImmutable::setTestNow('2026-11-20 21:35:00');
        [$client, $s, $item] = $this->patient('Asia/Gaza');
        $this->logAt($client, $item, '2026-11-20T23:30:00+02:00');

        // Sat 21 Nov, the 20:00 Riyadh run (= 19:00 in Gaza). Nothing logged on Sat in Gaza.
        CarbonImmutable::setTestNow('2026-11-21 17:00:00');
        Artisan::call('notifications:send-log-reminders');
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default']);

        $row = DB::table('patient_notifications')->where('user_id', $client->id)->where('type', 'log_reminder')->first();
        $this->assertNotNull($row, 'Gaza patient got no 20:00 reminder for Sat although nothing is logged on their Saturday');
    }

    /** Control: same data with SCHEDULE_TIMEZONE=Asia/Gaza, the reminder IS written (proves the test above fails only on the day cut). */
    public function test_control_reminder_reaches_gaza_patient_under_gaza_server(): void
    {
        config(['scheduling.timezone' => 'Asia/Gaza']);
        CarbonImmutable::setTestNow('2026-11-20 21:35:00');
        [$client, $s, $item] = $this->patient('Asia/Gaza');
        $this->logAt($client, $item, '2026-11-20T23:30:00+02:00');
        CarbonImmutable::setTestNow('2026-11-21 18:00:00');
        Artisan::call('notifications:send-log-reminders');
        $this->assertTrue(DB::table('patient_notifications')->where('user_id', $client->id)->where('type', 'log_reminder')->exists());
    }

    /** Clock-time view: what local time each scheduled push lands at for a Gaza patient (winter). */
    public function test_scheduled_push_times_land_at_the_intended_local_hour_for_gaza_under_riyadh(): void
    {
        config(['scheduling.timezone' => 'Asia/Riyadh']);
        $s = app(SelfScheduler::class);
        $now = CarbonImmutable::parse('2026-11-21 23:59:00', 'UTC');
        $got = [];
        foreach (['alerts' => '06:00', 'morning' => '07:30', 'reminders' => '20:00', 'measurements' => '09:00'] as $task => $intended) {
            $got[$task] = $s->latestSlot($task, $now)->setTimezone('Asia/Gaza')->format('H:i');
        }
        $this->assertSame(['alerts' => '06:00', 'morning' => '07:30', 'reminders' => '20:00', 'measurements' => '09:00'], $got);
    }

    /**
     * Appointment slots: availability 09:00-10:00 is read in SCHEDULE_TIMEZONE. A Gaza
     * nutritionist meaning 09:00 Gaza gets slots that the app (device-local, json_read.dart
     * jsonDateTime ->toLocal) shows Gaza patients one hour earlier in winter.
     */
    public function test_slot_set_for_0900_shows_0900_to_a_gaza_patient_under_riyadh(): void
    {
        config(['scheduling.timezone' => 'Asia/Riyadh']);
        CarbonImmutable::setTestNow('2026-11-21 06:00:00');
        [$client, $s, $item, $nutritionist] = $this->patient('Asia/Gaza');
        DB::table('nutritionist_availability')->insert([
            'nutritionist_id' => $nutritionist->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $slots = app(AppointmentService::class)->slots($nutritionist, array_key_first(Appointment::DURATIONS));
        $first = CarbonImmutable::parse(collect($slots)->flatten()->first());
        $this->assertSame('09:00', $first->setTimezone('Asia/Gaza')->format('H:i'), 'first slot as a Gaza device shows it');
    }
}
