<?php

namespace Tests\Feature\Ai;

use App\Exceptions\Ai\AiGenerationException;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Audit BE-16: every clinic shares one free-tier Groq budget (about 8k tokens a
 * minute). Nothing guarded it: one clinic could drain it, a timeout left no
 * usage row, and a draft waited on the provider's 429. A platform-wide budget
 * per minute (with a per-clinic share) refuses fast instead; the Monday batch,
 * which nobody waits for, waits for the next minute instead.
 */
class AiBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A non-reasoning model, so the request's answer budget is exactly max_completion_tokens.
        config([
            'ai.base_url' => 'https://fake-llm.test', 'ai.api_key' => 'k', 'ai.model' => 'llama-3.3-70b',
            'ai.budget.tokens_per_minute' => 2500, 'ai.budget.clinic_share' => 1.0,
        ]);
        $this->travelTo(now()->startOfMinute()->addSeconds(5));
    }

    /** One call that reserves about 1,010 tokens up front. */
    private function ask(?int $clinic = 1, array $context = []): array
    {
        return app(OpenAiCompatibleClient::class)->chatJson(
            [['role' => 'user', 'content' => 'hi']],
            ['max_completion_tokens' => 1000],
            ['feature' => 'ai_draft', 'nutritionist_id' => $clinic] + $context,
        );
    }

    private function fakeUsing(int $totalTokens): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => $totalTokens - 1, 'total_tokens' => $totalTokens]])]);
    }

    public function test_the_platform_budget_refuses_fast_without_calling_the_provider(): void
    {
        $this->fakeUsing(1000);

        $this->ask();
        $this->ask();

        try {
            $this->ask();
            $this->fail('the third call in the minute should have been refused');
        } catch (AiGenerationException $e) {
            // 503, not 429: the draft and summary services wait out a 429, and a refusal must not.
            $this->assertSame(503, $e->getCode());
            $this->assertGreaterThanOrEqual(1, $e->retryAfter);
        }

        Http::assertSentCount(2);
    }

    public function test_the_real_usage_frees_what_was_reserved_for_but_not_used(): void
    {
        $this->fakeUsing(100);

        for ($i = 0; $i < 5; $i++) {
            $this->ask();
        }

        Http::assertSentCount(5);
    }

    public function test_one_clinic_cannot_take_more_than_its_share(): void
    {
        config(['ai.budget.tokens_per_minute' => 4200, 'ai.budget.clinic_share' => 0.5]);
        $this->fakeUsing(1000);

        $this->ask(1);
        $this->ask(1);
        try {
            $this->ask(1);
            $this->fail('clinic 1 went over its half of the budget');
        } catch (AiGenerationException) {
        }

        $this->ask(2);

        Http::assertSentCount(3);
    }

    public function test_the_budget_resets_with_the_minute(): void
    {
        $this->fakeUsing(1000);
        $this->ask();
        $this->ask();

        $this->travel(60)->seconds();

        $this->ask();
        Http::assertSentCount(3);
    }

    public function test_a_timeout_leaves_a_failed_usage_row_and_keeps_its_reservation(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $clinic = User::factory()->nutritionist()->create();

        try {
            $this->ask($clinic->id);
        } catch (AiGenerationException) {
        }

        $row = AiUsageLog::query()->sole();
        $this->assertFalse((bool) $row->success);
        $this->assertSame($clinic->id, $row->nutritionist_id);
        $this->assertSame('ai_draft', $row->feature);
    }

    public function test_a_background_job_waits_for_the_next_minute_instead_of_failing(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $this->fakeUsing(1000);
        $this->ask();
        $this->ask();

        $this->ask(1, ['wait_for_budget' => 70]);

        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 1);
        Http::assertSentCount(3);
    }

    public function test_a_budget_of_zero_switches_it_off(): void
    {
        config(['ai.budget.tokens_per_minute' => 0]);
        $this->fakeUsing(5000);

        for ($i = 0; $i < 4; $i++) {
            $this->ask();
        }

        Http::assertSentCount(4);
    }
}
