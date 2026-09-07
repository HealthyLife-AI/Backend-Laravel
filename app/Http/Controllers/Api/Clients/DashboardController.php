<?php

namespace App\Http\Controllers\Api\Clients;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F-2: the dashboard overview stat cards (total / active / pending /
 * on-track / needs-attention / late / not-logged-today). One aggregate
 * query with conditional SUMs rather than six separate `count()` calls —
 * a single table scan instead of six.
 *
 * Adherence and "not logged today" are computed honestly from real data:
 * with no logging feature yet (that lands in a later sprint), every
 * client's `adherence_status` and `last_logged_at` are still null, so
 * those counts are correctly 0 / "everyone" respectively — never
 * fabricated to look populated.
 */
class DashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $today = now()->startOfDay();

        $row = Subscriber::query()
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(status = 'active') as active")
            ->selectRaw("sum(status = 'pending') as pending")
            ->selectRaw("sum(adherence_status = 'on_track') as on_track")
            ->selectRaw("sum(adherence_status = 'needs_attention') as needs_attention")
            ->selectRaw("sum(adherence_status = 'late') as late")
            ->selectRaw('sum(status = \'active\' and (last_logged_at is null or last_logged_at < ?)) as not_logged_today', [$today])
            ->first();

        return response()->json([
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'pending' => (int) $row->pending,
            'on_track' => (int) $row->on_track,
            'needs_attention' => (int) $row->needs_attention,
            'late' => (int) $row->late,
            'not_logged_today' => (int) $row->not_logged_today,
        ]);
    }
}
