<?php

namespace Tests\Feature\AiSummaries;

use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** S5-04/S5-05: the scheduled job's iteration scope and per-client failure isolation. */
class GenerateWeeklySummariesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_generates_a_summary_for_every_active_subscriber(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $active = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);
        $pending = Subscriber::factory()->create(['nutritionist_id' => $nutritionist->id]);

        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();

        $this->assertSame(1, $active->aiSummaries()->count());
        $this->assertSame(0, $pending->aiSummaries()->count());
    }

    public function test_it_returns_success_with_no_active_subscribers(): void
    {
        $this->artisan('ai-summaries:generate-weekly')->assertSuccessful();
        $this->assertDatabaseCount('ai_summaries', 0);
    }

    public function test_regenerate_non_arabic_rewrites_only_english_summaries(): void
    {
        $nutritionist = User::factory()->nutritionist()->create();
        $subscriber = Subscriber::factory()->active()->create(['nutritionist_id' => $nutritionist->id]);

        $english = $subscriber->aiSummaries()->create([
            'week_start' => '2026-09-07', 'summary_text' => 'Good adherence this week.', 'is_fallback' => false, 'generated_at' => now(),
        ]);
        $arabic = $subscriber->aiSummaries()->create([
            'week_start' => '2026-09-14', 'summary_text' => 'التزام جيد هذا الأسبوع.', 'is_fallback' => false, 'generated_at' => now(),
        ]);

        $this->artisan('ai-summaries:generate-weekly', ['--regenerate-non-arabic' => true])->assertSuccessful();

        $this->assertSame(2, $subscriber->aiSummaries()->count());
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $english->fresh()->summary_text);
        $this->assertSame('التزام جيد هذا الأسبوع.', $arabic->fresh()->summary_text);
    }
}
