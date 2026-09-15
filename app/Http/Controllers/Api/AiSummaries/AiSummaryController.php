<?php

namespace App\Http\Controllers\Api\AiSummaries;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiSummaryResource;
use App\Models\Subscriber;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * S5-04 / FR-21, ai_summary.view (nutritionist-only per the permission
 * matrix): one client's weekly summaries, newest first. `$subscriber` is
 * route-bound, so ownership is re-checked here — see
 * `Subscriber::belongsToCaller()`.
 */
class AiSummaryController extends Controller
{
    public function index(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return AiSummaryResource::collection($subscriber->aiSummaries()->paginate());
    }
}
