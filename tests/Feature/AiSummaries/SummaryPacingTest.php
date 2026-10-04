<?php

namespace Tests\Feature\AiSummaries;

use App\Models\Subscriber;
use App\Models\User;
use App\Services\AiSummaries\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The weekly run spaces its LLM calls (2 s between patients) and, on a 429,
 * waits as long as the provider's Retry-After asks (capped), so a Monday run
 * for many patients doesn't fall back en masse.
 */
class SummaryPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sleep::fake();
    }

    private function useLlm(): void
    {
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'test-key', 'ai.summary.model' => 'test-model']);
    }

    private function ok(): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['summary' => 'التزام جيد هذا الأسبوع مع تسجيل منتظم للوجبات.'])]]]];
    }

    private function patients(int $count): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        Subscriber::factory()->count($count)->active()->forNutritionist($nutritionist)->create();
    }

    public function test_the_weekly_run_waits_two_seconds_between_patients(): void
    {
        $this->useLlm();
        Http::fake(['*' => Http::response($this->ok())]);
        $this->patients(4);

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
            Sleep::for(2)->seconds(),
            Sleep::for(2)->seconds(),
        ]);
    }

    public function test_no_pacing_when_no_provider_is_configured(): void
    {
        $this->patients(3);

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        Sleep::assertNeverSlept();
    }

    public function test_a_429_with_retry_after_waits_that_long(): void
    {
        $this->useLlm();
        $this->patients(1);
        Http::fakeSequence()
            ->push(null, 429, ['Retry-After' => '7'])
            ->push($this->ok());

        $summary = app(WeeklySummaryService::class)->generateForWeek(Subscriber::first(), CarbonImmutable::now()->subWeek()->startOfWeek());

        $this->assertFalse($summary->is_fallback);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_an_oversized_retry_after_is_capped(): void
    {
        $this->useLlm();
        $this->patients(1);
        Http::fakeSequence()
            ->push(null, 429, ['Retry-After' => '3600'])
            ->push($this->ok());

        app(WeeklySummaryService::class)->generateForWeek(Subscriber::first(), CarbonImmutable::now()->subWeek()->startOfWeek());

        Sleep::assertSlept(fn (CarbonInterval $d) => (int) $d->totalSeconds === 120, 1);
    }

    public function test_without_retry_after_the_fixed_backoff_is_used(): void
    {
        $this->useLlm();
        $this->patients(1);
        Http::fakeSequence()
            ->push(null, 429)
            ->push($this->ok());

        app(WeeklySummaryService::class)->generateForWeek(Subscriber::first(), CarbonImmutable::now()->subWeek()->startOfWeek());

        Sleep::assertSequence([Sleep::for(15)->seconds()]);
    }
}
