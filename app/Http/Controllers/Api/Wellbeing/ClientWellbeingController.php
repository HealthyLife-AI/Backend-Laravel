<?php

namespace App\Http\Controllers\Api\Wellbeing;

use App\Http\Controllers\Controller;
use App\Http\Resources\WellbeingLogResource;
use App\Models\Subscriber;
use App\Services\Wellbeing\WellbeingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 4, C3: a patient's wellbeing log for their own nutritionist: the entries
 * of a range (default the last 14 days) and the 14-day summary.
 */
class ClientWellbeingController extends Controller
{
    public function __construct(private readonly WellbeingService $wellbeing) {}

    public function show(Subscriber $subscriber, Request $request): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json([
            'logs' => WellbeingLogResource::collection($this->wellbeing->range($subscriber, $data['from'] ?? null, $data['to'] ?? null))->resolve(),
            'summary' => $this->wellbeing->summary($subscriber),
        ]);
    }
}
