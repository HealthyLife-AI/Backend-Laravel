<?php

namespace App\Console\Commands\AiSummaries;

use App\Models\Subscriber;
use App\Services\AiSummaries\WeeklySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * S5-04 / FR-21. Runs weekly, one week AFTER the week it summarises ends
 * — the week just closed, not the one in progress — so every log for
 * that week has already landed before the summary is written.
 *
 * One client's failure (LLM unreachable, or anything else) must not stop
 * the rest — same principle as `EvaluateAlerts` and
 * `SendLogReminders`, and explicitly what S5-05 asks be verified for
 * this job specifically.
 */
class GenerateWeeklySummaries extends Command
{
    protected $signature = 'ai-summaries:generate-weekly';

    protected $description = 'Generate the FR-21 weekly natural-language summary for every active client';

    public function handle(WeeklySummaryService $summaries): int
    {
        $weekStart = CarbonImmutable::now()->subWeek()->startOfWeek();
        $subscribers = Subscriber::where('status', 'active')->get();
        $fallbacks = 0;
        $failures = 0;

        foreach ($subscribers as $subscriber) {
            try {
                $summary = $summaries->generateForWeek($subscriber, $weekStart);
                if ($summary->is_fallback) {
                    $fallbacks++;
                }
            } catch (\Throwable $e) {
                $failures++;
                report($e);
                $this->error("Summary generation failed for subscriber {$subscriber->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Generated %d summar(y/ies) for week of %s (%d via fallback, %d failure(s)).',
            $subscribers->count() - $failures,
            $weekStart->toDateString(),
            $fallbacks,
            $failures,
        ));

        return self::SUCCESS;
    }
}
