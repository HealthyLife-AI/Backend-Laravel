<?php

namespace App\Console\Commands\Ai;

use App\Models\AiUsageLog;
use Illuminate\Console\Command;

/** Daily: deletes ai_usage_logs older than the retention window (13 months by default). */
class PruneAiUsage extends Command
{
    protected $signature = 'ai-usage:prune';

    protected $description = 'Delete AI usage rows older than the retention window (config ai.usage_retention_months)';

    public function handle(): int
    {
        $months = max(1, (int) config('ai.usage_retention_months'));
        $deleted = AiUsageLog::query()->where('created_at', '<', now()->subMonths($months))->delete();

        $this->info("{$deleted} AI usage row(s) older than {$months} months deleted.");

        return self::SUCCESS;
    }
}
