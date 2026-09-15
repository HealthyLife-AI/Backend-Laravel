<?php

namespace App\Console\Commands\Alerts;

use App\Models\Subscriber;
use App\Services\Alerts\AlertEvaluationService;
use Illuminate\Console\Command;

/**
 * S5-01 / FR-20: the daily job. Runs against `Subscriber::where('status',
 * 'active')` with no authenticated user — `NutritionistScope` is
 * deliberately not applied outside a request (see that class's own
 * docblock), so this iterates every active client across every
 * nutritionist by design, not by a scope bypass.
 *
 * One client's failure must not stop the job for the rest — that's the
 * same principle S5-05 states for the weekly-summary job. Each
 * evaluation is wrapped individually and a failure is logged, not thrown.
 */
class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate FR-20 proactive alert rules for every active client';

    public function handle(AlertEvaluationService $alerts): int
    {
        $subscribers = Subscriber::where('status', 'active')->get();
        $failures = 0;

        foreach ($subscribers as $subscriber) {
            try {
                $alerts->evaluate($subscriber);
            } catch (\Throwable $e) {
                $failures++;
                report($e);
                $this->error("Alert evaluation failed for subscriber {$subscriber->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('Evaluated %d active client(s), %d failure(s).', $subscribers->count(), $failures));

        return self::SUCCESS;
    }
}
