<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Scheduling\SelfScheduler;
use App\Support\TrustedProxies;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Same purpose as `AiStatusController` / `FcmStatusController`: answer
 * "is this actually working on the server?" from outside the container.
 * The scheduled jobs fail silently by design (no run looks exactly like a
 * run with nothing to do), and on Taqat the cron fires each run in a
 * one-off container whose output and log file are gone afterwards. The
 * run markers live in the shared cache store (`CACHE_STORE=database`), so
 * they survive that and can be read here.
 *
 * Reports, per job: when it last ran, whether it is overdue now, and for
 * the 06:00 job what it did (patients checked, adherence status changes,
 * current stable / declining / stopped counts, failures).
 */
class SchedulerStatusController extends Controller
{
    public function __invoke(SelfScheduler $scheduler, Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $jobs = [];

        foreach (array_keys(SelfScheduler::TASKS) as $task) {
            $jobs[$task] = [
                'last_ran_at' => $scheduler->lastRan($task)?->toIso8601String(),
                'last_slot' => $scheduler->latestSlot($task, $now)->toIso8601String(),
                'overdue' => $scheduler->isDue($task, $now),
            ];
        }

        $jobs['alerts']['last_result'] = $scheduler->lastResult('alerts');

        return response()->json([
            'timezone' => config('scheduling.timezone'),
            'self_trigger' => (bool) config('scheduling.self_trigger'),
            'jobs' => $jobs,
            // B1: what the app sees of this very request. remote_addr is the
            // proxy's address when the app sits behind one — that is the value
            // for TRUSTED_PROXIES. trusted_proxy_match false while TRUSTED_PROXIES
            // is set means the proxy's address changed (e.g. after a redeploy).
            'request' => [
                'client_ip' => $request->ip(),
                'remote_addr' => $request->server('REMOTE_ADDR'),
                'forwarded_for' => $request->headers->get('X-Forwarded-For'),
                'forwarded_proto' => $request->headers->get('X-Forwarded-Proto'),
                'scheme' => $request->getScheme(),
                'trusted_proxies' => config('trustedproxy.proxies'),
                'trusted_proxy_match' => TrustedProxies::covers($request->server('REMOTE_ADDR'), config('trustedproxy.proxies')),
            ],
        ]);
    }
}
