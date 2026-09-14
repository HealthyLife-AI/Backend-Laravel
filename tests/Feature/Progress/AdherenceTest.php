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

    /** Builds one period's worth of logs at a known on-plan ratio. */
    private function logRatio(Subscriber $subscriber, MealItem $item, int $onPlan, int $offPlan, int $daysAgo): void
    {
        for ($i = 0; $i < $onPlan; $i++) {
            $this->log($subscriber, $item, $daysAgo);
        }
        for ($i = 0; $i < $offPlan; $i++) {
            $this->log($subscriber, null, $daysAgo);
        }
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

    public function test_a_steady_client_is_stable_even_below_the_reference(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();

        // ~65% both periods: under the 70% reference, but going nowhere.
        // Kholod's answer is the point of this test — a level alone is not
        // what prompts intervention, so this client raises nothing.
        $this->logRatio($subscriber, $planned, onPlan: 13, offPlan: 7, daysAgo: 2);
        $this->logRatio($subscriber, $planned, onPlan: 13, offPlan: 7, daysAgo: 9);
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('stable', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    /**
     * The case the old level-based rule got wrong: 85% -> 72% is a 13pp
     * fall and must be surfaced even though 72% is still above the 70%
     * reference (BR-14, FR-30).
     */
    public function test_a_material_decline_is_surfaced_even_above_the_reference(): void
    {
        [$nutritionist, $subscriber, $planned] = $this->makeClientWithPlan();

        $this->logRatio($subscriber, $planned, onPlan: 18, offPlan: 7, daysAgo: 2);   // 72%
        $this->logRatio($subscriber, $planned, onPlan: 17, offPlan: 3, daysAgo: 9);   // 85%
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('declining', app(AdherenceService::class)->refreshStatus($subscriber));

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence",
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertSame('declining', $response->json('status'));
        $this->assertEquals(72, $response->json('adherence_percent'));
        $this->assertEquals(85, $response->json('previous.adherence_percent'));
        $this->assertEquals(-13, $response->json('change_pp'));
        // FR-30: the threshold ships as context, never as the classifier.
        $this->assertSame(70, $response->json('reference_percent'));
    }

    /** A drop smaller than the configured material decline is not an alert. */
    public function test_a_slight_dip_is_still_stable(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();

        $this->logRatio($subscriber, $planned, onPlan: 16, offPlan: 4, daysAgo: 2);   // 80%
        $this->logRatio($subscriber, $planned, onPlan: 17, offPlan: 3, daysAgo: 9);   // 85%
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('stable', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    /** A client who improved is obviously not declining. */
    public function test_an_improving_client_is_stable(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();

        $this->logRatio($subscriber, $planned, onPlan: 18, offPlan: 2, daysAgo: 2);   // 90%
        $this->logRatio($subscriber, $planned, onPlan: 12, offPlan: 8, daysAgo: 9);   // 60%
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('stable', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    /**
     * Absence of a prior rate is not evidence of a fall — guessing a
     * direction from a single level is the inference the interviews ruled
     * out, so a first period reads as stable.
     */
    public function test_a_first_period_with_no_history_is_stable(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();

        $this->logRatio($subscriber, $planned, onPlan: 1, offPlan: 9, daysAgo: 1);
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        $this->assertSame('stable', app(AdherenceService::class)->refreshStatus($subscriber));
        $this->assertNull(app(AdherenceService::class)->summary($subscriber)['change_pp']);
    }

    /**
     * Staleness is checked before anything about the rate: a client who
     * logged one perfect meal a fortnight ago scores 100% over any window
     * containing it, and calling that stable would hide exactly the
     * client the nutritionist most needs to see.
     */
    public function test_a_client_who_stopped_logging_is_flagged_despite_perfect_history(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->log($subscriber, $planned, daysAgo: 14);
        $subscriber->forceFill(['last_logged_at' => now()->subDays(14)])->save();

        $this->assertSame('stopped_logging', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    public function test_a_client_who_never_logged_is_stopped_logging(): void
    {
        [, $subscriber] = $this->makeClientWithPlan();

        $this->assertSame('stopped_logging', app(AdherenceService::class)->refreshStatus($subscriber));
    }

    public function test_logging_writes_the_status_onto_the_subscriber(): void
    {
        [, $subscriber, $planned] = $this->makeClientWithPlan();
        $this->assertNull($subscriber->adherence_status);

        $this->log($subscriber, $planned);
        $subscriber->forceFill(['last_logged_at' => now()])->save();
        app(AdherenceService::class)->refreshStatus($subscriber);

        $this->assertSame('stable', $subscriber->fresh()->adherence_status);
    }

    /** The preceding window is the same length, immediately before. */
    public function test_the_previous_window_is_the_equally_long_period_before(): void
    {
        [$nutritionist, $subscriber] = $this->makeClientWithPlan();

        $response = $this->getJson(
            "/api/v1/clients/{$subscriber->id}/adherence?from=".now()->subDays(6)->toDateString()
            .'&to='.now()->toDateString(),
            $this->bearerFor($nutritionist)
        );

        $response->assertOk();
        $this->assertSame(now()->subDays(13)->toDateString(), $response->json('previous.from'));
        $this->assertSame(now()->subDays(7)->toDateString(), $response->json('previous.to'));
    }
}
