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
        // lazy(), not get(): this runs across every nutritionist's active
        // clients with no pagination anywhere else in the call chain, so
        // a growing pilot roster must not load the whole table into
        // memory in one query — lazy() pages through it (default 1000
        // rows/query) at the same total DB cost.
        $total = 0;
        $failures = 0;

        Subscriber::where('status', 'active')->lazy()->each(function (Subscriber $subscriber) use ($alerts, &$total, &$failures): void {
            $total++;

            try {
                $alerts->evaluate($subscriber);
            } catch (\Throwable $e) {
                $failures++;
                report($e);
                $this->error("Alert evaluation failed for subscriber {$subscriber->id}: {$e->getMessage()}");
            }
        });

        $this->info(sprintf('Evaluated %d active client(s), %d failure(s).', $total, $failures));

        return self::SUCCESS;
    }
}
