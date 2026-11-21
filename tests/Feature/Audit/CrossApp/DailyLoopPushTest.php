<?php

namespace Tests\Feature\Audit\CrossApp;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\NotificationPreference;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FakesPush;
use Tests\TestCase;

/**
 * J4: the daily push jobs with FCM faked. Who is targeted, what text lands on
 * the lock screen, in which language, and whether a second run re-sends.
 */
class DailyLoopPushTest extends TestCase
{
    use FakesPush, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fakePush();
        config(['scheduling.timezone' => 'Asia/Riyadh']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function patient(string $locale, bool $archived = false): User
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id, 'fcm_token' => 'tok-'.$locale.'-'.Str::random(5)]);
        $client->assignRole('client');
        $s = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $client->id,
            'created_at' => '2026-11-01 08:00:00', 'activated_at' => '2026-11-01 08:00:00']);
        if ($archived) {
            $s->forceFill(['archived_at' => '2026-11-15 08:00:00'])->save();
        }
        NotificationPreference::for($client)->fill(['locale' => $locale])->save();
        $planId = DB::table('meal_plans')->insertGetId(['subscriber_id' => $s->id, 'created_by' => $nutritionist->id, 'status' => 'active',
            'activated_at' => '2026-11-01 08:00:00', 'created_at' => '2026-11-01 08:00:00', 'updated_at' => now()]);
        $mealId = DB::table('meals')->insertGetId(['meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        MealItem::create(['meal_id' => $mealId, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'sort_order' => 0]);

        return $client;
    }

    public function test_morning_and_reminder_pushes_target_active_patients_in_their_language_once(): void
    {
        $ar = $this->patient('ar');
        $en = $this->patient('en');
        $archived = $this->patient('ar', archived: true);

        CarbonImmutable::setTestNow('2026-11-21 04:30:00'); // 07:30 Riyadh
        Artisan::call('morning:send');
        Artisan::call('morning:send'); // second run the same morning
        CarbonImmutable::setTestNow('2026-11-21 17:00:00'); // 20:00 Riyadh
        Artisan::call('notifications:send-log-reminders');
        Artisan::call('notifications:send-log-reminders');

        $byToken = collect($this->pushes)->groupBy('token');
        file_put_contents(base_path('docs/audit/evidence/crossapp/j4-push-payloads.json'), json_encode($this->pushes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertArrayNotHasKey($archived->fcm_token, $byToken->all(), 'archived patient got a push');
        $this->assertSame(['صباح الخير', 'لا تنسَ تسجيل وجباتك اليوم'], $byToken[$ar->fcm_token]->pluck('title')->all());
        $this->assertSame(['Good morning', "Don't forget to log your meals today"], $byToken[$en->fcm_token]->pluck('title')->all());
        foreach ($this->pushes as $p) {
            $this->assertSame(['notification_id', 'category', 'type'], array_keys($p['data']), 'payload carries only ids/types');
        }
    }
}
