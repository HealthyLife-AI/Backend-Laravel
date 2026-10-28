<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsageLog;
use App\Models\Food;
use App\Models\HealthProfile;
use App\Models\MealPlan;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\AiSummaries\WeeklySummaryService;
use App\Support\ClinicDay;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesForApi;
use Tests\TestCase;

/** Real token usage and cost: one row per provider response, no text, cost at read time, a monthly cap on drafts. */
class AiUsageTrackingTest extends TestCase
{
    use AuthenticatesForApi, RefreshDatabase;

    private const SECRET = 'SECRET-PROMPT-سلمى-0599123456';

    private User $admin;

    private User $nutritionist;

    private User $other;

    private Subscriber $subscriber;

    private array $adminToken;

    private array $nutritionistToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'openai/gpt-oss-120b']);

        $this->admin = User::factory()->admin()->create();
        $this->nutritionist = User::factory()->nutritionist()->create(['name' => 'Nutritionist One']);
        $this->other = User::factory()->nutritionist()->create(['name' => 'Nutritionist Two']);
        $client = User::factory()->client($this->nutritionist)->create();
        $this->subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $this->nutritionist->id, 'user_id' => $client->id]);

        $this->adminToken = $this->bearerFor($this->admin);
        $this->nutritionistToken = $this->bearerFor($this->nutritionist);
    }

    /** @param  array<string, mixed>  $usage */
    private function reply(string $content, array $usage = []): array
    {
        return ['choices' => [['message' => ['content' => $content]]]] + ($usage === [] ? [] : ['usage' => $usage]);
    }

    private function usage(int $in = 100, int $out = 50, ?int $reasoning = null, ?int $cached = null): array
    {
        return ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out]
            + ($reasoning === null ? [] : ['completion_tokens_details' => ['reasoning_tokens' => $reasoning]])
            + ($cached === null ? [] : ['prompt_tokens_details' => ['cached_tokens' => $cached]]);
    }

    private function ask(array $context = [], string $prompt = self::SECRET)
    {
        return (new OpenAiCompatibleClient)->chatJson([['role' => 'user', 'content' => $prompt]], ['max_completion_tokens' => 100], $context);
    }

    // ---- a row per call ---------------------------------------------------------------------------

    public function test_a_row_is_written_with_the_usage_the_provider_reported(): void
    {
        Http::fake(['*' => Http::response($this->reply('{"a":1}', $this->usage(1200, 340, reasoning: 200, cached: 800)))]);

        $this->ask(['feature' => 'plan_summary', 'nutritionist_id' => $this->nutritionist->id, 'subscriber_id' => $this->subscriber->id]);

        $row = AiUsageLog::sole();
        $this->assertSame(['plan_summary', $this->nutritionist->id, $this->subscriber->id, 'openai/gpt-oss-120b'], [$row->feature, $row->nutritionist_id, $row->subscriber_id, $row->model]);
        $this->assertSame([1200, 340, 200, 800, 1540, 1, true], [$row->prompt_tokens, $row->completion_tokens, $row->reasoning_tokens, $row->cached_tokens, $row->total_tokens, $row->attempt, $row->success]);
        $this->assertGreaterThanOrEqual(0, $row->latency_ms);
        $this->assertNotNull($row->created_at);
    }

    public function test_optional_details_are_null_and_a_missing_usage_is_zero(): void
    {
        Http::fake(['*' => Http::sequence()->push($this->reply('{"a":1}', $this->usage(10, 5)))->push($this->reply('{"a":1}'))]);

        $this->ask();
        $this->ask();

        [$first, $second] = AiUsageLog::orderBy('id')->get()->all();
        $this->assertSame([null, null, 15], [$first->reasoning_tokens, $first->cached_tokens, $first->total_tokens]);
        $this->assertSame([0, 0, 0], [$second->prompt_tokens, $second->completion_tokens, $second->total_tokens]);
        $this->assertSame('other', $second->feature, 'no context: other');
    }

    public function test_a_refused_json_mode_attempt_and_its_retry_are_two_rows(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'json_validate_failed', 'message' => 'x', 'failed_generation' => self::SECRET]], 400)
            ->push($this->reply('{"a":1}', $this->usage(100, 40)))]);

        $this->ask(['feature' => 'weight_insight']);

        $rows = AiUsageLog::orderBy('id')->get();
        $this->assertSame([[1, false, 0], [2, true, 140]], $rows->map(fn ($r) => [$r->attempt, $r->success, $r->total_tokens])->all());
    }

    public function test_a_failed_response_is_a_row_with_success_false(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);

        try {
            $this->ask();
        } catch (\Throwable) {
        }

        $this->assertFalse(AiUsageLog::sole()->success);
    }

    public function test_an_unknown_feature_is_stored_as_other(): void
    {
        Http::fake(['*' => Http::response($this->reply('{"a":1}', $this->usage()))]);

        $this->ask(['feature' => 'something_new']);

        $this->assertSame('other', AiUsageLog::sole()->feature);
    }

    public function test_no_prompt_or_reply_text_is_stored_anywhere(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['code' => 'json_validate_failed', 'message' => self::SECRET, 'failed_generation' => self::SECRET]], 400)
            ->push($this->reply('{"text":"'.self::SECRET.'"}', $this->usage()))]);

        $this->ask(['feature' => 'ai_draft', 'nutritionist_id' => $this->nutritionist->id], self::SECRET);

        $stored = json_encode(DB::table('ai_usage_logs')->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET', $stored);
        $this->assertStringNotContainsString('سلمى', $stored);
        $this->assertSame(
            ['attempt', 'cached_tokens', 'completion_tokens', 'created_at', 'feature', 'id', 'latency_ms', 'model', 'nutritionist_id', 'prompt_tokens', 'reasoning_tokens', 'subscriber_id', 'success', 'total_tokens'],
            collect(Schema::getColumnListing('ai_usage_logs'))->sort()->values()->all(),
            'only counts, ids and a feature name: no text column exists',
        );
    }

    // ---- every call site ---------------------------------------------------------------------------------

    public function test_the_plan_summary_suggestion_logs_its_context(): void
    {
        $food = Food::factory()->create(['allergens' => [], 'calories_per_100g' => 200, 'protein_g_per_100g' => 10, 'carbs_g_per_100g' => 20, 'fat_g_per_100g' => 8]);
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        $plan = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans", ['meals' => [['name' => 'breakfast', 'items' => [['food_id' => $food->id, 'quantity_grams' => 300]]]]], $this->nutritionistToken)->assertCreated()->json();
        Http::fake(['*' => Http::response($this->reply('{"summary":"نمط متوازن. متابعة بعد أسبوعين."}', $this->usage(500, 80)))]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/{$plan['id']}/summary-suggestion", [], $this->nutritionistToken)->assertOk();

        $row = AiUsageLog::sole();
        $this->assertSame(['plan_summary', $this->nutritionist->id, $this->subscriber->id], [$row->feature, $row->nutritionist_id, $row->subscriber_id]);
    }

    public function test_the_weight_insight_logs_its_context(): void
    {
        \App\Models\BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => ClinicDay::today()->subDays(14)->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => 80]);
        \App\Models\BodyCompositionReading::create(['subscriber_id' => $this->subscriber->id, 'recorded_at' => ClinicDay::today()->toDateString(), 'source' => 'clinic-analyser', 'weight_kg' => 79]);
        Http::fake(['*' => Http::response($this->reply('{"text":"نزل وزنك شوي. وأخصائيتك بتتابع معك."}', $this->usage(300, 60)))]);

        $this->getJson("/api/v1/clients/{$this->subscriber->id}/weight-insight", $this->nutritionistToken)->assertOk();

        $row = AiUsageLog::sole();
        $this->assertSame(['weight_insight', $this->nutritionist->id, $this->subscriber->id], [$row->feature, $row->nutritionist_id, $row->subscriber_id]);
    }

    public function test_the_ai_draft_logs_its_context_and_a_second_pass_is_attempt_two(): void
    {
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        Food::factory()->count(6)->create(['allergens' => []]);
        // An answer that fails validation twice: the service retries once, so two provider responses.
        Http::fake(['*' => Http::response($this->reply('{"meals":"nonsense"}', $this->usage(4000, 900)))]);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful();

        $rows = AiUsageLog::orderBy('id')->get();
        $this->assertSame(['ai_draft', $this->nutritionist->id, $this->subscriber->id], [$rows[0]->feature, $rows[0]->nutritionist_id, $rows[0]->subscriber_id]);
        $this->assertSame([1, 2], $rows->pluck('attempt')->all());
    }

    public function test_the_weekly_summary_logs_its_context(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'k', 'ai.summary.model' => 'openai/gpt-oss-120b']);
        Http::fake(['*' => Http::response($this->reply('{"summary":"'.str_repeat('ملخص ', 20).'"}', $this->usage(700, 120)))]);

        app(WeeklySummaryService::class)->generateForWeek($this->subscriber, CarbonImmutable::parse('2026-10-19'));

        $row = AiUsageLog::sole();
        $this->assertSame(['weekly_summary', $this->nutritionist->id, $this->subscriber->id], [$row->feature, $row->nutritionist_id, $row->subscriber_id]);
    }

    public function test_ping_logs_a_row_with_no_owner(): void
    {
        Http::fake(['*' => Http::response($this->reply('{"ok": true}', $this->usage(20, 5)))]);

        $this->artisan('ai:ping')->assertSuccessful();

        $row = AiUsageLog::sole();
        $this->assertSame(['ping', null, null], [$row->feature, $row->nutritionist_id, $row->subscriber_id]);
    }

    // ---- logging never breaks the call ---------------------------------------------------------------------------------

    public function test_a_logging_failure_does_not_fail_the_call_and_is_only_a_warning(): void
    {
        Http::fake(['*' => Http::response($this->reply('{"a":1}', $this->usage()))]);
        Schema::drop('ai_usage_logs');
        Log::spy();

        $this->assertSame(['a' => 1], $this->ask(['feature' => 'ai_draft']));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'usage') && ! str_contains(json_encode($context), 'SECRET'))->once();
    }

    // ---- the monthly cap -----------------------------------------------------------------------------------------------------

    private function draftSetUp(): void
    {
        HealthProfile::create(['subscriber_id' => $this->subscriber->id, 'weight_kg' => 70, 'height_cm' => 170, 'age' => 30, 'gender' => 'female', 'activity_level' => 'light', 'daily_calorie_needs' => 1900]);
        Food::factory()->count(6)->create(['allergens' => []]);
        Http::fake(['*' => Http::response(null, 500)]);
    }

    private function logDraft(User $nutritionist, bool $success = true, ?\DateTimeInterface $at = null, string $feature = 'ai_draft'): void
    {
        AiUsageLog::create(['feature' => $feature, 'nutritionist_id' => $nutritionist->id, 'model' => 'm', 'success' => $success, 'created_at' => $at ?? now()]);
    }

    public function test_at_the_cap_the_draft_is_the_rule_based_one_with_a_message_and_the_model_is_not_called(): void
    {
        config(['ai.max_drafts_per_nutritionist_per_month' => 2]);
        $this->draftSetUp();
        $this->logDraft($this->nutritionist);
        $this->logDraft($this->nutritionist);

        $response = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful();

        Http::assertNothingSent();
        $this->assertTrue($response->json('is_ai_fallback'));
        $this->assertContains('ai_draft_limit_reached', $response->json('warnings'));
        $this->assertStringContainsString('الحد الشهري', $response->json('ai_limit.message_ar'));
        $this->assertStringContainsString('monthly limit', $response->json('ai_limit.message_en'));
        $this->assertNotEmpty($response->json('meals'), 'a real draft, not an error');
        $this->assertSame(2, AiUsageLog::count(), 'a skipped call costs nothing and logs nothing');
    }

    public function test_below_the_cap_the_model_is_called_and_there_is_no_limit_message(): void
    {
        config(['ai.max_drafts_per_nutritionist_per_month' => 2]);
        $this->draftSetUp();
        $this->logDraft($this->nutritionist);

        $response = $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful();

        Http::assertSent(fn () => true);
        $this->assertNull($response->json('ai_limit'));
        $this->assertNotContains('ai_draft_limit_reached', $response->json('warnings'));
    }

    public function test_only_successful_ai_draft_rows_of_this_nutritionist_this_month_count(): void
    {
        config(['ai.max_drafts_per_nutritionist_per_month' => 2]);
        $this->draftSetUp();
        $this->logDraft($this->nutritionist, success: false);
        $this->logDraft($this->nutritionist, feature: 'plan_summary');
        $this->logDraft($this->other);
        $this->logDraft($this->nutritionist, at: now()->subMonths(2));
        $this->logDraft($this->nutritionist);   // one counted

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful()->assertJsonPath('ai_limit', null);
        Http::assertSent(fn () => true);
    }

    public function test_zero_means_unlimited_and_the_default_is_300(): void
    {
        $this->assertSame(300, config('ai.max_drafts_per_nutritionist_per_month'));
        config(['ai.max_drafts_per_nutritionist_per_month' => 0]);
        $this->draftSetUp();
        foreach (range(1, 5) as $i) {
            $this->logDraft($this->nutritionist);
        }

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful()->assertJsonPath('ai_limit', null);
        Http::assertSent(fn () => true);
    }

    public function test_the_cap_is_per_nutritionist(): void
    {
        config(['ai.max_drafts_per_nutritionist_per_month' => 1]);
        $this->draftSetUp();
        $this->logDraft($this->other);   // the other one is at the cap, not this one

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $this->nutritionistToken)->assertSuccessful()->assertJsonPath('ai_limit', null);
    }

    public function test_the_cap_renews_with_the_calendar_month_in_the_clinic_timezone(): void
    {
        config(['ai.max_drafts_per_nutritionist_per_month' => 1]);
        $this->draftSetUp();
        $token = $this->nutritionistToken;
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30', config('scheduling.timezone')));
        $this->logDraft($this->nutritionist);

        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $token)->assertSuccessful()->assertJsonPath('is_ai_fallback', true);
        $this->assertNotNull(AiUsageLog::count());

        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', config('scheduling.timezone')));
        Http::fake(['*' => Http::response(null, 500)]);
        $this->postJson("/api/v1/clients/{$this->subscriber->id}/meal-plans/ai-draft", [], $token)->assertSuccessful()->assertJsonPath('ai_limit', null);
    }

    // ---- the report ----------------------------------------------------------------------------------------------------------------------

    private function row(string $feature, ?User $nutritionist, int $in, int $out, ?int $cached = null, string $at = '2026-10-10 10:00:00', bool $success = true): void
    {
        AiUsageLog::create(['feature' => $feature, 'nutritionist_id' => $nutritionist?->id, 'model' => 'm', 'prompt_tokens' => $in, 'completion_tokens' => $out, 'cached_tokens' => $cached, 'total_tokens' => $in + $out, 'success' => $success, 'created_at' => $at]);
    }

    private function patient(User $nutritionist, string $status, ?string $activated, ?string $archived): Subscriber
    {
        $user = User::factory()->client($nutritionist)->create();

        return tap(Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id, 'user_id' => $user->id]), fn ($s) => $s->forceFill(['status' => $status, 'activated_at' => $activated, 'archived_at' => $archived])->save());
    }

    public function test_the_endpoint_math_matches_the_fixture_rows(): void
    {
        // Prices: 0.15 in, 0.075 cached, 0.60 out per million.
        $this->row('ai_draft', $this->nutritionist, 1_000_000, 500_000, cached: 200_000);   // 0.12 + 0.015 + 0.30 = 0.435
        $this->row('ai_draft', $this->nutritionist, 2_000_000, 0);                          // 0.30
        $this->row('plan_summary', $this->nutritionist, 100_000, 100_000, success: false);   // 0.015 + 0.06 = 0.075
        $this->row('weight_insight', $this->other, 1_000_000, 1_000_000);                   // 0.15 + 0.60 = 0.75
        $this->row('ping', null, 10_000, 10_000);                                           // 0.0015 + 0.006 = 0.0075
        $this->row('ai_draft', $this->nutritionist, 9_000_000, 9_000_000, at: '2026-09-30 10:00:00');   // another month
        $this->row('ai_draft', $this->nutritionist, 9_000_000, 9_000_000, at: '2026-11-01 10:00:00');   // another month

        $report = $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->assertOk();

        $this->assertSame(['2026-10', '2026-10-01', '2026-10-31'], [$report->json('month'), $report->json('from'), $report->json('to')]);

        $byFeature = collect($report->json('by_feature'))->keyBy('feature');
        $this->assertEquals(0.735, $byFeature['ai_draft']['cost_usd']);
        $this->assertSame([2, 2], [$byFeature['ai_draft']['calls'], $byFeature['ai_draft']['successful_calls']]);
        $this->assertSame([3_000_000, 500_000, 200_000], [$byFeature['ai_draft']['prompt_tokens'], $byFeature['ai_draft']['completion_tokens'], $byFeature['ai_draft']['cached_tokens']]);
        $this->assertEquals(0.075, $byFeature['plan_summary']['cost_usd']);
        $this->assertSame([1, 0], [$byFeature['plan_summary']['calls'], $byFeature['plan_summary']['successful_calls']]);
        $this->assertEquals(0.75, $byFeature['weight_insight']['cost_usd']);
        $this->assertEquals(0.0075, $byFeature['ping']['cost_usd']);
        $this->assertSame(['weight_insight', 'ai_draft', 'plan_summary', 'ping'], collect($report->json('by_feature'))->pluck('feature')->all(), 'dearest first');

        $this->assertSame(5, $report->json('totals.calls'));
        $this->assertEquals(1.5675, $report->json('totals.cost_usd'));
        $this->assertSame(4_110_000, $report->json('totals.prompt_tokens'));
        $this->assertEquals(['input' => 0.15, 'output' => 0.6, 'cached_input' => 0.075], $report->json('prices_usd_per_million'));
    }

    public function test_the_top_nutritionists_and_the_cost_per_active_patient(): void
    {
        $this->row('ai_draft', $this->nutritionist, 1_000_000, 500_000, cached: 200_000);   // 0.435
        $this->row('plan_summary', $this->nutritionist, 100_000, 100_000);                  // 0.075  -> 0.51
        $this->row('weight_insight', $this->other, 1_000_000, 1_000_000);                   // 0.75
        $this->row('ping', null, 10_000, 10_000);                                           // no owner: totals only

        // $this->nutritionist already has the setUp patient (active, from the factory). Add the rest.
        $this->patient($this->nutritionist, 'active', '2026-10-01 00:00:00', null);                    // counts
        $this->patient($this->nutritionist, 'active', '2026-08-01 00:00:00', '2026-10-10 00:00:00');   // archived mid-month: counts
        $this->patient($this->nutritionist, 'active', '2026-08-01 00:00:00', '2026-09-20 00:00:00');   // archived before: no
        $this->patient($this->nutritionist, 'active', '2026-11-02 00:00:00', null);                    // activated after: no
        $this->patient($this->nutritionist, 'pending', null, null);                                    // never active: no
        $this->patient($this->other, 'active', '2026-09-01 00:00:00', null);
        $this->patient($this->other, 'active', '2026-09-01 00:00:00', null);
        Subscriber::withoutGlobalScopes()->where('id', $this->subscriber->id)->update(['status' => 'active', 'activated_at' => '2026-09-15 00:00:00', 'archived_at' => null]);

        $report = $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->assertOk();
        $top = collect($report->json('top_nutritionists'))->keyBy('id');

        $this->assertSame([$this->other->id, $this->nutritionist->id], collect($report->json('top_nutritionists'))->pluck('id')->all(), 'dearest first, the ping has no owner');
        $this->assertEquals([0.75, 2, 0.375], [$top[$this->other->id]['cost_usd'], $top[$this->other->id]['active_patients'], $top[$this->other->id]['cost_per_active_patient_usd']]);
        $this->assertEquals([0.51, 3, 0.17], [$top[$this->nutritionist->id]['cost_usd'], $top[$this->nutritionist->id]['active_patients'], $top[$this->nutritionist->id]['cost_per_active_patient_usd']], 'the setUp patient + two of the four added');
        $this->assertSame('Nutritionist One', $top[$this->nutritionist->id]['name']);
        $this->assertSame(2, $top[$this->nutritionist->id]['calls']);

        // The headline: all spend (0.51 + 0.75 + 0.0075) over every active patient of the platform (3 + 2).
        $this->assertSame(5, $report->json('active_patients'));
        $this->assertEquals(round(1.2675 / 5, 4), $report->json('cost_per_active_patient_usd'));
        $this->assertStringContainsString('not archived', $report->json('active_patient_definition'));
    }

    public function test_the_top_list_is_at_most_ten(): void
    {
        foreach (range(1, 12) as $i) {
            $this->row('ai_draft', User::factory()->nutritionist()->create(), 1_000_000 * $i, 0);
        }

        $this->assertCount(10, $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->json('top_nutritionists'));
    }

    public function test_no_patients_means_a_null_cost_per_patient_not_a_division_error(): void
    {
        Subscriber::withoutGlobalScopes()->delete();
        $this->row('ping', null, 1000, 1000);

        $report = $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->assertOk();

        $this->assertSame(0, $report->json('active_patients'));
        $this->assertNull($report->json('cost_per_active_patient_usd'));
    }

    public function test_an_empty_month_is_zeros(): void
    {
        $report = $this->getJson('/api/v1/admin/ai-usage?month=2020-01', $this->adminToken)->assertOk();

        $this->assertSame([0, 0.0, [], []], [$report->json('totals.calls'), (float) $report->json('totals.cost_usd'), $report->json('by_feature'), $report->json('top_nutritionists')]);
    }

    public function test_the_default_month_is_this_month_and_a_bad_month_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', config('scheduling.timezone')));
        $this->row('ping', null, 1000, 1000, at: '2026-10-05 10:00:00');
        $admin = $this->adminToken;

        $this->getJson('/api/v1/admin/ai-usage', $admin)->assertOk()->assertJsonPath('month', '2026-10')->assertJsonPath('totals.calls', 1);
        $this->getJson('/api/v1/admin/ai-usage?month=2026-13', $admin)->assertStatus(422);
        $this->getJson('/api/v1/admin/ai-usage?month=October', $admin)->assertStatus(422);
    }

    public function test_the_month_boundary_is_the_clinic_day(): void
    {
        // 21:30 UTC on Sep 30 is 00:30 on Oct 1 in Riyadh.
        $this->row('ping', null, 1000, 1000, at: '2026-09-30 21:30:00');

        $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->assertJsonPath('totals.calls', 1);
        $this->getJson('/api/v1/admin/ai-usage?month=2026-09', $this->adminToken)->assertJsonPath('totals.calls', 0);
    }

    public function test_cost_follows_the_configured_prices_when_read(): void
    {
        $this->row('ping', null, 1_000_000, 1_000_000);
        $this->assertEquals(0.75, $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->json('totals.cost_usd'));

        config(['ai.price_per_million_input' => 1.0, 'ai.price_per_million_output' => 2.0]);

        $this->assertEquals(3.0, $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->json('totals.cost_usd'), 'the history is re-priced, nothing was stored');
        $this->assertSame(['price_per_million_input' => 0.15, 'price_per_million_output' => 0.6, 'price_per_million_cached_input' => 0.075], (function () {
            $config = include base_path('config/ai.php');

            return array_intersect_key($config, array_flip(['price_per_million_input', 'price_per_million_output', 'price_per_million_cached_input']));
        })());
    }

    public function test_only_the_admin_can_read_it(): void
    {
        $this->getJson('/api/v1/admin/ai-usage', $this->nutritionistToken)->assertForbidden();
        $this->getJson('/api/v1/admin/ai-usage', $this->bearerFor(User::factory()->client($this->nutritionist)->create()))->assertForbidden();
        $this->getJson('/api/v1/admin/ai-usage')->assertUnauthorized();
        $this->getJson('/api/v1/admin/ai-usage', $this->adminToken)->assertOk();
    }

    public function test_the_report_carries_no_text_and_no_patient_data(): void
    {
        $this->row('ai_draft', $this->nutritionist, 100, 100);

        $body = $this->getJson('/api/v1/admin/ai-usage?month=2026-10', $this->adminToken)->getContent();

        $this->assertStringNotContainsString($this->subscriber->code, $body);
        $this->assertStringNotContainsString('subscriber', $body);
    }

    // ---- retention ----------------------------------------------------------------------------------------------------------------------

    public function test_rows_older_than_13_months_are_pruned_and_newer_ones_stay(): void
    {
        $this->row('ping', null, 1, 1, at: now()->subMonths(14)->toDateTimeString());
        $this->row('ping', null, 1, 1, at: now()->subMonths(13)->subDay()->toDateTimeString());
        $this->row('ping', null, 1, 1, at: now()->subMonths(12)->toDateTimeString());
        $this->row('ping', null, 1, 1, at: now()->toDateTimeString());

        $this->artisan('ai-usage:prune')->expectsOutputToContain('2 AI usage row(s) older than 13 months deleted')->assertSuccessful();

        $this->assertSame(2, AiUsageLog::count());
    }

    public function test_the_prune_job_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'ai-usage:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * *', $events->first()->expression);
    }

    public function test_deleting_a_nutritionist_or_a_patient_keeps_the_cost_rows(): void
    {
        $this->row('ai_draft', $this->other, 100, 100);
        AiUsageLog::query()->update(['subscriber_id' => $this->subscriber->id]);

        $this->other->delete();
        Subscriber::withoutGlobalScopes()->where('id', $this->subscriber->id)->delete();

        $row = AiUsageLog::sole();
        $this->assertSame([null, null], [$row->nutritionist_id, $row->subscriber_id]);
    }
}
