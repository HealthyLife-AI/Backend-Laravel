<?php

namespace App\Http\Controllers\Api\Today;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Today\TodaySummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 3, B2/B4: the morning message. The patient reads their own; the
 * nutritionist previews exactly what a given patient of hers sees today.
 */
class TodayController extends Controller
{
    public function __construct(private readonly TodaySummary $today) {}

    public function mine(): JsonResponse
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return response()->json($this->today->for($subscriber));
    }

    public function forClient(Subscriber $subscriber): JsonResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return response()->json($this->today->for($subscriber));
    }
}
