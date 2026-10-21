<?php

namespace App\Services\Alerts;

use App\Models\Subscriber;
use App\Services\Adherence\AdherenceService;

/**
 * The daily check for ONE patient: recompute the stored adherence status,
 * then evaluate the alert rules. The 06:00 job runs it for everyone; the
 * nutritionist's alert-settings save runs it for the one patient whose
 * thresholds just changed, so the new numbers show at once instead of
 * tomorrow morning.
 *
 * Each step is wrapped on its own: a failed status refresh still lets the
 * alerts be evaluated, and the reverse. A failure is reported and counted,
 * never thrown - one patient never stops the rest.
 */
class SubscriberCheck
{
    public function __construct(
        private readonly AdherenceService $adherence,
        private readonly AlertEvaluationService $alerts,
    ) {}

    /**
     * @return array{status: string|null, status_changed: bool, failures: list<string>}
     */
    public function run(Subscriber $subscriber): array
    {
        $result = ['status' => null, 'status_changed' => false, 'failures' => []];

        try {
            $before = $subscriber->adherence_status;
            $result['status'] = $this->adherence->refreshStatus($subscriber);
            $result['status_changed'] = $result['status'] !== $before;
        } catch (\Throwable $e) {
            report($e);
            $result['failures'][] = "Adherence refresh failed for subscriber {$subscriber->id}: {$e->getMessage()}";
        }

        try {
            $this->alerts->evaluate($subscriber);
        } catch (\Throwable $e) {
            report($e);
            $result['failures'][] = "Alert evaluation failed for subscriber {$subscriber->id}: {$e->getMessage()}";
        }

        return $result;
    }
}
