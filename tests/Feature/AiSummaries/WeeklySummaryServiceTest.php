<?php

namespace Tests\Feature\AiSummaries;

use App\Models\Food;
use App\Models\MealItem;
use App\Models\MealLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\AiSummaries\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S5-03/S5-04/S5-05 / FR-21: the weekly summary — LLM path, validation,
 * and the fail-closed fallback. `phpunit.xml` forces OPENAI_API_KEY empty
 * by default (see AiDraftLlmTest's precedent); each test here opts back
 * in explicitly where it exercises the LLM path.
 */
class WeeklySummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeSubscriber(): Subscriber
    {
        $nutritionist = User::factory()->nutritionist()->create();

        return Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
    }

    private function weekStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->subWeek()->startOfWeek();
    }

    private function chatCompletion(array $content): array
    {
        return ['choices' => [['message' => ['content' => json_encode($content)]]]];
    }

    public function test_falls_back_to_a_template_when_no_provider_is_configured(): void
    {
        $subscriber = $this->makeSubscriber();
        Http::fake(); // any call here would be a bug

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
        $this->assertNotEmpty($summary->summary_text);
        Http::assertNothingSent();
    }

    public function test_a_client_with_no_logs_gets_a_no_data_fallback(): void
    {
        $subscriber = $this->makeSubscriber();

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
        $this->assertStringContainsString('not enough data', $summary->summary_text);
    }

    /**
     * `ai.summary.*`, not `ai.base_url`/`ai.api_key`/`ai.model` — the
     * follow-up that gave WeeklySummaryService its own optional
     * credential profile means it no longer reads the top-level `ai.*`
     * keys at all (see AppServiceProvider's contextual binding). Every
     * test below opts into the LLM path through that nested config.
     */
    public function test_uses_the_llms_summary_when_the_response_is_valid(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
        $subscriber = $this->makeSubscriber();

        Http::fake(['*' => Http::response($this->chatCompletion([
            'summary' => 'Great consistency logging breakfast this week; try logging dinner more regularly too.',
        ]))]);

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertFalse($summary->is_fallback);
        $this->assertSame('Great consistency logging breakfast this week; try logging dinner more regularly too.', $summary->summary_text);
    }

    public function test_falls_back_when_the_llm_response_is_too_short(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
        $subscriber = $this->makeSubscriber();

        Http::fake(['*' => Http::response($this->chatCompletion(['summary' => 'Good.']))]);

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
    }

    public function test_falls_back_when_the_llm_omits_the_summary_key(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
        $subscriber = $this->makeSubscriber();

        Http::fake(['*' => Http::response($this->chatCompletion(['note' => 'not the right key']))]);

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
    }

    public function test_falls_back_when_the_llm_request_fails(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
        $subscriber = $this->makeSubscriber();

        Http::fake(['*' => Http::response(null, 500)]);

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
        $this->assertNotEmpty($summary->summary_text);
    }

    /** Never fails outright — a bad LLM response must still leave the nutritionist with SOMETHING, per S5-05. */
    public function test_falls_back_when_the_llm_returns_unparseable_content(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
        $subscriber = $this->makeSubscriber();

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
    }

    /** Re-running the job for an already-summarised week updates that row instead of duplicating it. */
    public function test_regenerating_the_same_week_replaces_rather_than_duplicates(): void
    {
        $subscriber = $this->makeSubscriber();
        $weekStart = $this->weekStart();

        $first = app(WeeklySummaryService::class)->generateForWeek($subscriber, $weekStart);
        $second = app(WeeklySummaryService::class)->generateForWeek($subscriber, $weekStart);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $subscriber->aiSummaries()->count());
    }

    public function test_the_fallback_reflects_a_declining_status(): void
    {
        $subscriber = $this->makeSubscriber();
        $weekStart = $this->weekStart();

        $nutritionist = $subscriber->nutritionist;
        $planId = DB::table('meal_plans')->insertGetId([
            'subscriber_id' => $subscriber->id, 'created_by' => $nutritionist->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mealId = DB::table('meals')->insertGetId([
            'meal_plan_id' => $planId, 'name' => 'lunch', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $planned = MealItem::create(['meal_id' => $mealId, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 300, 'sort_order' => 0]);
        // classify() checks staleness via last_logged_at before the rate
        // — without this the subscriber reads as stopped_logging
        // regardless of what was actually logged, per AdherenceTest's
        // own established precedent.
        $subscriber->forceFill(['last_logged_at' => now()])->save();

        // This week (the summarised week): mostly off-plan.
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $planned->food_id, 'meal_item_id' => $planned->id, 'quantity_grams' => 100, 'logged_at' => $weekStart->addDay()]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'logged_at' => $weekStart->addDay()]);
        MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => Food::factory()->create()->id, 'quantity_grams' => 100, 'logged_at' => $weekStart->addDay()]);
        // Previous week: perfect.
        foreach (range(1, 4) as $i) {
            MealLog::create(['subscriber_id' => $subscriber->id, 'food_id' => $planned->food_id, 'meal_item_id' => $planned->id, 'quantity_grams' => 100, 'logged_at' => $weekStart->subDays($i)]);
        }

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $weekStart);

        $this->assertStringContainsString('decline', $summary->summary_text);
    }

    // --- credential isolation (S5-03 follow-up) -------------------------

    /**
     * The actual point of the split: configuring the AI-draft feature's
     * key must not turn the weekly-summary LLM path on. Before the
     * contextual binding, both features read the same `ai.*` keys and
     * necessarily shared one Groq quota; this proves they no longer do.
     */
    public function test_configuring_only_the_draft_key_does_not_enable_the_summary_llm(): void
    {
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'test-key', 'ai.model' => 'test-model']);
        config(['ai.summary.base_url' => null, 'ai.summary.api_key' => null, 'ai.summary.model' => null]);
        $subscriber = $this->makeSubscriber();

        Http::fake(); // any call here means the isolation failed

        $summary = app(WeeklySummaryService::class)->generateForWeek($subscriber, $this->weekStart());

        $this->assertTrue($summary->is_fallback);
        Http::assertNothingSent();
    }

    /**
     * And the reverse: a summary-only key must not leak into the draft
     * feature's client — a fresh, unbound `OpenAiCompatibleClient`
     * resolution (what `AiDraftPlanService` gets) still reads only the
     * top-level `ai.*` keys, ignoring `ai.summary.*` entirely.
     */
    public function test_configuring_only_the_summary_key_does_not_enable_the_draft_llm(): void
    {
        config(['ai.base_url' => null, 'ai.api_key' => null, 'ai.model' => null]);
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);

        $this->assertFalse(app(OpenAiCompatibleClient::class)->isConfigured());
    }
}
