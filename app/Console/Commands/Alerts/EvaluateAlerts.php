<?php

namespace App\Console\Commands\Alerts;

use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;
use App\Services\Alerts\SubscriberCheck;
use App\Services\Scheduling\SelfScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * S5-01 / FR-20 + S4-03 / BR-14: the daily 06:00 job. For every patient in
 * `Subscriber::active()` (activated, not archived) it first recomputes the
 * stored adherence status, then evaluates the alert rules.
 *
 * Both steps run here, per patient, rather than as two scheduled commands,
 * so they cannot disagree: they see the same patient set and the same
 * "now", and the no-log alert and `stopped_logging` apply the same rule
 * (`adherence.late_after_days`) to the same `last_logged_at`. The self-
 * trigger fallback (SelfScheduler) also knows only this one job, so a
 * second command "just before" it would have no guaranteed order when the
 * jobs are caught up after a quiet night. Without this step the stored
 * status was only rewritten when the patient logged a meal, so a patient
 * who stopped logging kept reading "stable" in the roster while this job
 * raised a no-log alert for them.
 *
 * Runs with no authenticated user — `NutritionistScope` is deliberately
 * not applied outside a request (see that class's own docblock), so this
 * iterates every active patient across every nutritionist by design.
 *
 * Pages through patients by id (lazyById, 200 per query) so a growing
 * roster never loads whole. One patient's failure never stops the rest:
 * each step is wrapped on its own, so a failed status refresh still lets
 * that patient's alerts be evaluated, and the reverse.
 */
class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate {--subscriber= : Evaluate only this patient (by id). Does not count as the daily run.}';

    protected $description = 'Recompute adherence status and evaluate FR-20 alert rules for every active client';

    public function handle(SubscriberCheck $check): int
    {
        if ($this->option('subscriber') !== null) {
            return $this->handleOne($check, (int) $this->option('subscriber'));
        }

        $total = 0;
        $changed = 0;
        $failures = 0;
        $statuses = [
            AdherenceService::STATUS_STABLE => 0,
            AdherenceService::STATUS_DECLINING => 0,
            AdherenceService::STATUS_STOPPED => 0,
        ];

        // `with('alertSettings')`: each patient's own thresholds are read
        // while checking them, and must not cost a query per patient.
        Subscriber::active()->with('alertSettings')->lazyById(200)->each(function (Subscriber $subscriber) use ($check, &$total, &$changed, &$failures, &$statuses): void {
            $total++;

            $outcome = $check->run($subscriber);

            if ($outcome['status'] !== null) {
                $statuses[$outcome['status']]++;
            }
            if ($outcome['status_changed']) {
                $changed++;
            }
            foreach ($outcome['failures'] as $message) {
                $failures++;
                $this->error($message);
            }
        });

        $result = ['patients' => $total, 'status_changes' => $changed, 'failures' => $failures] + $statuses;
        $scheduler = app(SelfScheduler::class);
        $scheduler->markRan('alerts');
        $scheduler->recordResult('alerts', $result);

        $line = sprintf(
            'Daily check: %d active client(s), %d adherence status change(s) (stable %d, declining %d, stopped %d), %d failure(s).',
            $total, $changed, $statuses['stable'], $statuses['declining'], $statuses['stopped_logging'], $failures,
        );
        Log::info('alerts:evaluate '.$line, $result);
        $this->info($line);

        return self::SUCCESS;
    }

    /**
     * One patient, on demand. Deliberately does NOT call markRan or
     * recordResult: those tell the scheduler (and the admin status page)
     * that the DAILY run happened, and a single-patient run is not that.
     */
    private function handleOne(SubscriberCheck $check, int $id): int
    {
        $subscriber = Subscriber::active()->with('alertSettings')->find($id);

        if ($subscriber === null) {
            $this->error("No active client with id {$id}.");

            return self::FAILURE;
        }

        $outcome = $check->run($subscriber);

        foreach ($outcome['failures'] as $message) {
            $this->error($message);
        }

        $this->info(sprintf('Checked client %d: status %s%s, %d failure(s).', $id, $outcome['status'] ?? 'unchanged', $outcome['status_changed'] ? ' (changed)' : '', count($outcome['failures'])));

        return $outcome['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
