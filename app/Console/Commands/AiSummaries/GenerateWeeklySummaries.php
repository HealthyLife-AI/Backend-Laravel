<?php

namespace App\Console\Commands\AiSummaries;

use App\Models\AiSummary;
use App\Models\Subscriber;
use App\Services\AiSummaries\WeeklySummaryService;
use App\Services\Scheduling\SelfScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * S5-04 / FR-21. Runs weekly, one week AFTER the week it summarises ends
 * — the week just closed, not the one in progress — so every log for
 * that week has already landed before the summary is written.
 *
 * One client's failure (LLM unreachable, or anything else) must not stop
 * the rest — same principle as `EvaluateAlerts` and
 * `SendLogReminders`, and explicitly what S5-05 asks be verified for
 * this job specifically.
 *
 * `--regenerate-non-arabic` is a one-off repair, not part of the schedule:
 * summaries written before the prompt was pinned to Arabic are stored in
 * English, and the frontend can't translate free prose. It re-runs the
 * same generator for exactly those rows' weeks (idempotent per week, so
 * each row is replaced, not duplicated). Makes one LLM call per row.
 */
class GenerateWeeklySummaries extends Command
{
    protected $signature = 'ai-summaries:generate-weekly
        {--regenerate-non-arabic : Rewrite already-stored summaries that contain no Arabic text, instead of generating last week}';

    protected $description = 'Generate the FR-21 weekly natural-language summary for every active client';

    private const SECONDS_BETWEEN_PATIENTS = 2;

    public function handle(WeeklySummaryService $summaries): int
    {
        if ($this->option('regenerate-non-arabic')) {
            return $this->regenerateNonArabic($summaries);
        }

        $weekStart = CarbonImmutable::now()->subWeek()->startOfWeek();
        // lazy(), not get() — see EvaluateAlerts's identical note. Same
        // unbounded-roster shape, same fix.
        $total = 0;
        $fallbacks = 0;
        $failures = 0;
        // A short gap between patients so a Monday run for many patients doesn't
        // hit the provider's per-minute limit back to back (429s, seen 2026-09-27).
        $pace = $summaries->usesLlm();

        Subscriber::active()->lazy()->each(function (Subscriber $subscriber) use ($summaries, $weekStart, $pace, &$total, &$fallbacks, &$failures): void {
            if ($pace && $total > 0) {
                Sleep::for(self::SECONDS_BETWEEN_PATIENTS)->seconds();
            }
            $total++;

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
        });

        app(SelfScheduler::class)->markRan('summaries');

        $this->info(sprintf(
            'Generated %d summar(y/ies) for week of %s (%d via fallback, %d failure(s)).',
            $total - $failures,
            $weekStart->toDateString(),
            $fallbacks,
            $failures,
        ));

        return self::SUCCESS;
    }

    private function regenerateNonArabic(WeeklySummaryService $summaries): int
    {
        $rewritten = 0;
        $failures = 0;

        AiSummary::with('subscriber')->lazy()->each(function (AiSummary $summary) use ($summaries, &$rewritten, &$failures): void {
            if (preg_match('/\p{Arabic}/u', $summary->summary_text) === 1 || $summary->subscriber === null) {
                return;
            }

            try {
                $summaries->generateForWeek($summary->subscriber, $summary->week_start->toImmutable());
                $rewritten++;
            } catch (\Throwable $e) {
                $failures++;
                report($e);
                $this->error("Regeneration failed for summary {$summary->id}: {$e->getMessage()}");
            }
        });

        $this->info(sprintf('Rewrote %d non-Arabic summar(y/ies) (%d failure(s)).', $rewritten, $failures));

        return self::SUCCESS;
    }
}
