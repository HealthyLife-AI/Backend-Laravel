<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Consent\ConsentService;
use App\Services\Scheduling\SelfScheduler;
use Illuminate\Http\JsonResponse;

/** Admin panel home: platform-wide counts, across every nutritionist. */
class AdminOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $foods = Food::query()
            ->selectRaw('source, status, count(*) as total')
            ->groupBy('source', 'status')
            ->get();

        $approved = $foods->where('status', 'approved');

        return response()->json([
            'nutritionists' => User::role('nutritionist')->count(),
            'clients' => Subscriber::withoutGlobalScopes()->count(),
            'active_clients' => Subscriber::withoutGlobalScopes()->active()->count(),
            'foods_total' => (int) $approved->sum('total'),
            'foods_pending' => (int) $foods->where('status', 'pending')->sum('total'),
            // Proof the 06:00 job (adherence refresh + alerts) ran: when, and what it did.
            'last_daily_run' => app(SelfScheduler::class)->lastResult('alerts'),
            // BR-17: the consent version and policy URL in force, and whether
            // each was set in the environment. The gate always works (a
            // default is used), but the admin panel warns while either is a
            // default, like it does for the scheduler.
            'consent' => app(ConsentService::class)->configuration(),
            'foods_by_source' => [
                'usda' => (int) $approved->where('source', 'usda')->sum('total'),
                'admin' => (int) $approved->where('source', 'admin')->sum('total'),
                'nutritionist' => (int) $approved->where('source', 'nutritionist')->sum('total'),
            ],
        ]);
    }
}
