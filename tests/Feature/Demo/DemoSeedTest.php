<?php

namespace Tests\Feature\Demo;

use App\Console\Commands\Demo\SeedDemoData;
use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\Food;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\ArabicFoodSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ArabicFoodSeeder::class);
        config(['demo.password' => 'demo-test-password']);
    }

    /** @return list<Subscriber> in the order the command creates them */
    private function demoPatients(): array
    {
        $nutritionist = User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->firstOrFail();

        return Subscriber::withoutGlobalScopes()->where('nutritionist_id', $nutritionist->id)->orderBy('id')->get()->all();
    }

    /** @return list<string> open alert types, sorted */
    private function openAlerts(Subscriber $subscriber): array
    {
        return $subscriber->alerts()->whereNull('resolved_at')->pluck('type')->sort()->values()->all();
    }

    public function test_each_pattern_produces_its_status_and_alerts(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        [$p1, $p2, $p3, $p4, $p5, $p6, $p7] = $this->demoPatients();

        foreach ([$p1, $p2, $p3] as $stable) {
            $this->assertSame('stable', $stable->adherence_status);
            $this->assertSame([], $this->openAlerts($stable));
        }

        $this->assertSame('declining', $p4->adherence_status);
        $this->assertSame([Alert::TYPE_CALORIES_EXCEEDED], $this->openAlerts($p4));

        $this->assertSame('stopped_logging', $p5->adherence_status);
        $this->assertSame([Alert::TYPE_NO_LOG], $this->openAlerts($p5));

        $this->assertSame('stable', $p6->adherence_status);
        $this->assertSame([Alert::TYPE_MILESTONE], $this->openAlerts($p6));

        $this->assertSame('pending', $p7->status);
        $this->assertNotNull($p7->healthProfile);
        $this->assertSame(0, $p7->mealPlans()->count());
        $this->assertSame(0, $p7->mealLogs()->count());

        // Written as if live: nothing late, every log has a meal type.
        $ids = collect([$p1, $p2, $p3, $p4, $p5, $p6])->pluck('id');
        $this->assertSame(0, MealLog::whereIn('subscriber_id', $ids)->where('is_late', true)->count());
        $this->assertSame(0, MealLog::whereIn('subscriber_id', $ids)->whereNull('meal_type')->count());

        // Last calendar week has logs for patients 1-6, so each has a summary (fallback here: no LLM in tests).
        $this->assertSame(6, AiSummary::whereIn('subscriber_id', $ids)->where('is_fallback', true)->count());
        $this->assertSame(0, $p7->aiSummaries()->count());

        // Logins: the dashboard, and patient 4 in the app.
        $nutritionist = User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first();
        $this->assertTrue($nutritionist->hasRole('nutritionist'));
        $this->assertTrue(Hash::check('demo-test-password', $nutritionist->password));
        $this->assertTrue(Hash::check('demo-test-password', $p4->user->password));
        $this->assertFalse(Hash::check('demo-test-password', $p1->user->password));
    }

    public function test_running_twice_creates_no_duplicates_and_leaves_other_data_alone(): void
    {
        $other = User::factory()->nutritionist()->create();
        $otherPatient = Subscriber::factory()->active()->forNutritionist($other)->create();

        $this->artisan('demo:seed')->assertSuccessful();
        $counts = fn () => [User::count(), Subscriber::withoutGlobalScopes()->count(), MealLog::count(), Alert::count(), AiSummary::count()];
        $first = $counts();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($first, $counts());
        $this->assertSame(1, User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->count());
        $this->assertCount(7, $this->demoPatients());
        $this->assertNotNull($other->fresh());
        $this->assertNotNull(Subscriber::withoutGlobalScopes()->find($otherPatient->id));
        $this->assertSame(0, Alert::where('subscriber_id', $otherPatient->id)->count());
    }

    public function test_production_refuses_without_force(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('demo:seed')->assertFailed();
        $this->assertNull(User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first());

        $this->artisan('demo:seed', ['--force' => true])->assertSuccessful();
        $this->assertNotNull(User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first());
    }

    public function test_any_environment_but_local_or_testing_needs_force(): void
    {
        foreach (['staging', 'prod'] as $env) {
            $this->app['env'] = $env;
            $this->artisan('demo:seed')->expectsOutputToContain('--force')->assertFailed();
        }

        $this->assertNull(User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first());
    }

    public function test_a_real_account_holding_the_demo_email_is_never_touched(): void
    {
        $real = User::factory()->nutritionist()->create(['email' => SeedDemoData::NUTRITIONIST_EMAIL]);
        $theirPatient = Subscriber::factory()->active()->forNutritionist($real)->create();

        $this->artisan('demo:seed')->assertFailed();

        $this->assertNotNull($real->fresh());
        $this->assertFalse($real->fresh()->is_demo);
        $this->assertNotNull(Subscriber::withoutGlobalScopes()->find($theirPatient->id));
        $this->assertSame(1, User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->count());
    }

    public function test_only_demo_flagged_accounts_are_created(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $nutritionist = User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first();
        $this->assertTrue($nutritionist->is_demo);
        $this->assertSame(7, User::where('nutritionist_id', $nutritionist->id)->where('is_demo', true)->count());
        $this->assertSame(8, User::where('is_demo', true)->count());
    }

    public function test_it_stops_when_the_password_or_the_foods_are_missing(): void
    {
        config(['demo.password' => null]);
        $this->artisan('demo:seed')->expectsOutputToContain('DEMO_PASSWORD')->assertFailed();

        config(['demo.password' => 'demo-test-password']);
        Food::query()->delete();
        $this->artisan('demo:seed')->expectsOutputToContain('ArabicFoodSeeder')->assertFailed();

        $this->assertNull(User::where('email', SeedDemoData::NUTRITIONIST_EMAIL)->first());
    }
}
