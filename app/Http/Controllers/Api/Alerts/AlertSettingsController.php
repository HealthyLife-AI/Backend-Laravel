<?php

namespace App\Http\Controllers\Api\Alerts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alerts\UpdateAlertSettingsRequest;
use App\Models\Subscriber;
use App\Models\SubscriberAlertSetting;
use App\Services\Alerts\AlertThresholds;
use App\Services\Alerts\SubscriberCheck;
use Illuminate\Http\JsonResponse;

/**
 * Pilot feedback 2, B: per-patient alert thresholds, set by the patient's
 * own nutritionist. Never reachable by the patient (the route's permission
 * is nutritionist-only), and another nutritionist's patient is a 404.
 */
class AlertSettingsController extends Controller
{
    public function __construct(
        private readonly AlertThresholds $thresholds,
        private readonly SubscriberCheck $check,
    ) {}

    public function show(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json($this->present($subscriber));
    }

    public function update(Subscriber $subscriber, UpdateAlertSettingsRequest $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validated();

        if ($data !== []) {
            SubscriberAlertSetting::query()->updateOrCreate(
                ['subscriber_id' => $subscriber->id],
                $data + ['updated_by' => $request->user()->id],
            );
        }

        $subscriber->unsetRelation('alertSettings');

        // The new numbers apply to THIS patient at once: alerts that no
        // longer hold are resolved and ones that now do are opened, rather
        // than waiting for tomorrow's 06:00 run. Only a patient the daily job
        // would look at (activated, in follow-up) is checked.
        if (Subscriber::query()->active()->whereKey($subscriber->id)->exists()) {
            $this->check->run($subscriber);
        }

        return response()->json($this->present($subscriber));
    }

    /** @return array{effective: array<string, int|float>, overrides: array<string, int|float|null>, defaults: array<string, int|float>} */
    private function present(Subscriber $subscriber): array
    {
        return [
            'effective' => $this->thresholds->for($subscriber),
            'overrides' => $this->thresholds->overrides($subscriber),
            'defaults' => $this->thresholds->defaults(),
        ];
    }
}
