<?php

namespace Tests\Feature\AiSummaries;

use App\Models\Subscriber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audit X-07: the Monday summary batch spends the platform's shared Groq budget
 * (about 8k tokens a minute, one call per patient). It must never run twice at
 * once (cron plus the request-triggered self-scheduler) and a finished week
 * must not be paid for again.
 */
class SummaryBatchBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Subscriber $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['ai.summary.base_url' => 'https://fake-llm.test', 'ai.summary.api_key' => 'k', 'ai.summary.model' => 'openai/gpt-oss-120b']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"summary":"'.str_repeat('ملخص ', 20).'"}']]], 'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 120]])]);
        // Monday 2026-11-23 10:00 clinic time: the week of 2026-11-16 just closed.
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', config('scheduling.timezone')));
        $this->patient = Subscriber::factory()->active()->create(['nutritionist_id' => User::factory()->nutritionist()->create()->id]);
    }

    public function test_a_run_that_starts_while_another_is_running_does_nothing(): void
    {
        $other = Cache::lock('ai-summaries:weekly', 3600);
        $this->assertTrue($other->get());

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, $this->patient->aiSummaries()->count());
        $other->release();
    }

    public function test_the_lock_is_released_when_a_run_finishes(): void
    {
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        $this->assertTrue(Cache::lock('ai-summaries:weekly', 5)->get(), 'the lock was left behind');
    }

    public function test_a_week_already_summarised_is_not_paid_for_again(): void
    {
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, $this->patient->aiSummaries()->count());
    }

    public function test_a_fallback_summary_is_retried(): void
    {
        $this->patient->aiSummaries()->create(['week_start' => '2026-11-16', 'summary_text' => 'ملخص آلي', 'is_fallback' => true, 'generated_at' => now()]);

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertFalse($this->patient->aiSummaries()->first()->is_fallback);
    }

    public function test_force_regenerates_a_finished_week(): void
    {
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();
        $this->artisan('ai-summaries:generate-weekly', ['--force' => true])->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_the_scheduled_entry_does_not_overlap_itself(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'ai-summaries:generate-weekly'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping, 'the Monday summaries entry can start while the previous run is still going');
    }
}
