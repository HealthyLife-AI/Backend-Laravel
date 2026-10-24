<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\PatientCondition;
use App\Models\Subscriber;

/**
 * Phase 1, C2: whether an alert about this patient is 'important'.
 *
 * Read from config/alerts.php at the moment the alert opens (see the
 * `severity` block there for the rule). One place, so the evaluation job and
 * a patient's quick request cannot disagree about who needs attention first.
 */
class AlertSeverity
{
    public function for(Subscriber $subscriber, string $type, ?string $reason = null): string
    {
        $rules = config('alerts.severity');

        if (! $this->typeCanBeImportant($rules, $type, $reason)) {
            return Alert::SEVERITY_NORMAL;
        }

        $codes = PatientCondition::query()->where('subscriber_id', $subscriber->id)->pluck('code')->all();

        return array_intersect($codes, (array) $rules['important_conditions']) !== []
            ? Alert::SEVERITY_IMPORTANT
            : Alert::SEVERITY_NORMAL;
    }

    /** @param  array<string, mixed>  $rules */
    private function typeCanBeImportant(array $rules, string $type, ?string $reason): bool
    {
        if ($type === Alert::TYPE_QUICK_REQUEST) {
            return in_array($reason, (array) $rules['important_quick_request_reasons'], true);
        }

        return in_array($type, (array) $rules['important_types'], true);
    }
}
