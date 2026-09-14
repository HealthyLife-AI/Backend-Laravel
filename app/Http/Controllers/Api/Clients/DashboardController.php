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
 * Adherence and "not logged today" are computed honestly from real data;
 * since Sprint 4 both columns are actually written (SRS §2.4.2), and a
 * client whose status has not been recomputed yet counts toward none of
 * the three rather than being defaulted into one.
 *
 * The three counts describe DIRECTION, not level (BR-14): `stable`,
 * `declining`, `stopped_logging`. They replaced on_track /
 * needs_attention / late in the S4-03 rework, after both nutritionists
 * said a percentage on its own is not what prompts intervention.
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
            ->selectRaw("sum(adherence_status = 'stable') as stable")
            ->selectRaw("sum(adherence_status = 'declining') as declining")
            ->selectRaw("sum(adherence_status = 'stopped_logging') as stopped_logging")
            ->selectRaw('sum(status = \'active\' and (last_logged_at is null or last_logged_at < ?)) as not_logged_today', [$today])
            ->first();

        return response()->json([
            'total' => (int) $row->total,
            'active' => (int) $row->active,
            'pending' => (int) $row->pending,
            'stable' => (int) $row->stable,
            'declining' => (int) $row->declining,
            'stopped_logging' => (int) $row->stopped_logging,
            'not_logged_today' => (int) $row->not_logged_today,
        ]);
    }
}
