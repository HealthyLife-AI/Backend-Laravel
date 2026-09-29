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
            // BR-17: whether CONSENT_VERSION is set. While it isn't, patient
            // data endpoints refuse (503) in production, so the admin panel
            // warns about it like it does for the scheduler.
            'consent_configured' => app(ConsentService::class)->isConfigured(),
            'foods_by_source' => [
                'usda' => (int) $approved->where('source', 'usda')->sum('total'),
                'admin' => (int) $approved->where('source', 'admin')->sum('total'),
                'nutritionist' => (int) $approved->where('source', 'nutritionist')->sum('total'),
            ],
        ]);
    }
}
