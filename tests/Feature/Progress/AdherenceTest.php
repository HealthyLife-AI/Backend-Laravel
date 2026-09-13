<?php

namespace Tests\Feature\Progress;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Adherence\AdherenceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/**
 * S4-03/S4-04 / FR-18, FR-19: plan-vs-actual and the progress charts.
 */
class AdherenceTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @return array{0: User, 1: Subscriber, 2: MealItem} */
    private function makeClientWithPlan(): array
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $client = User::factory()->create(['nutritionist_id' => $nutritionist->id]);
        $client->assignRole('client');
        $subscriber = Subscriber::factory()->create([
            'nutritionist_id' => $nutritionist->id,
            'user_id' => $client->id,
        ]);

        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planned = MealItem::create([
            'meal_id' => $mealId,
            'food_id' => Food::factory()->create()->id,
            'quantity_grams' => 300,
            'sort_order' => 0,
        ]);

        return [$nutritionist, $subscriber, $planned];
    }

    private function log(Subscriber $subscriber, ?MealItem $item, int $daysAgo = 0): MealLog
    {
        return MealLog::create([
            'subscriber_id' => $subscriber->id,
            'food_id' => $item?->food_id ?? Food::factory()->create()->id,
            'meal_item_id' => $item?->id,
            'quantity_grams' => 100,
            'logged_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_adherence_is_the_share_of_logs_that_reference_the_plan(): void
    {
        [$nutritionist, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->log($subscriber, $planned);
        $this->log($subscriber, $planned);
        $this->log($subscriber, $planned);
        $this->log($subscriber, null); // ate outside the plan

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertSame(4, $response->json('total_logs'));
        $this->assertSame(3, $response->json('on_plan_logs'));
        $this->assertSame(1, $response->json('off_plan_logs'));
        // JSON renders 75.0 as 75, so compare by value not by type.
        $this->assertEquals(75, $response->json('adherence_percent'));
    }

    /**
     * "0% adherent" and "hasn't logged anything" are different clinical
     * statements — the profile screen has a separate empty state for the
     * second, so the API must not collapse them into the same number.
     */
    public function test_a_client_with_no_logs_reports_null_not_zero(): void
    {
        [$nutritionist, $subscriber] = $this->makeClientWithPlan();

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertSame(0, $response->json('total_logs'));
        $this->assertNull($response->json('adherence_percent'));
    }

    public function test_the_window_excludes_logs_outside_it(): void
    {
        [$nutritionist, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->log($subscriber, $planned, daysAgo: 0);
        $this->log($subscriber, $planned, daysAgo: 40);

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence?from=".now()->subDays(7)->toDateString()
            .'&to='.now()->toDateString(),
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('total_logs'));
    }

    public function test_a_nutritionist_cannot_read_another_nutritionists_client_adherence(): void
    {
        [, $subscriber] = $this->makeClientWithPlan();
        $outsider = User::factory()->nutritionist()->create();

        $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence",
            $this->bearerFor($outsider)
        )->assertNotFound();
    }

    public function test_logging_sets_adherence_status_on_the_subscriber(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->assertNull($subscriber->adherence_status);

        $this->log($subscriber, $planned);
        $subscriber->forceFill(['last_logged_at' => now()])->save();
        app(AdherenceService::class)->refreshStatus($subscriber);

        $this->assertSame('on_track', $subscriber->fresh()->adherence_status);
    }

    public function test_mostly_off_plan_logging_is_flagged_as_needs_attention(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->log($subscriber, $planned);
        $this->log($subscriber, null);
        $this->log($subscriber, null);
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('needs_attention', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    /**
     * A client who logged one perfect meal a fortnight ago is 100%
     * "adherent" over any window containing it. Calling that on-track
     * would hide exactly the client the nutritionist most needs to see,
     * so staleness is checked before the percentage.
     */
    public function test_a_client_who_stopped_logging_is_late_despite_perfect_history(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->log($subscriber, $planned, daysAgo: 14);
        $subscriber->forceFill(['last_logged_at' => now()->subDays(14)])->save();

        $this->assertSame('late', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    public function test_a_client_who_never_logged_is_late(): void
    {
        [, $subscriber] = $this->makeClientWithPlan();

        $this->assertSame('late', app(AdherenceService::class)->refreshStatus($subscriber));
    }
}
