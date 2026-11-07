<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use App\Services\Scheduling\SelfScheduler;
use App\Support\TrustedProxies;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        // Not a self-triggered task (see bootstrap/app.php), so no slot or overdue flag: just the last outcome.
        $jobs['backup'] = ['last_ran_at' => $scheduler->lastRan('backup')?->toIso8601String(), 'last_result' => $scheduler->lastResult('backup')];

        return response()->json([
            'queue' => $this->queue(),
            'runtime' => $this->runtime(),
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

    /**
     * Queued work (the push fan-out) and what failed. The queue is worked by the every-minute cron, so
     * a growing `pending` or a large `oldest_pending_seconds` means the cron is not running.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return ['connection' => $connection];
        }

        try {
            $oldest = DB::table('jobs')->min('created_at');

            return [
                'connection' => $connection,
                'pending' => DB::table('jobs')->count(),
                'oldest_pending_seconds' => $oldest === null ? null : max(0, time() - (int) $oldest),
                'failed' => DB::table('failed_jobs')->count(),
            ];
        } catch (Throwable) {
            return ['connection' => $connection, 'error' => 'jobs tables unreadable'];
        }
    }

    /**
     * What THIS serving process runs with, so a deploy can be verified from outside (a shell in the
     * container is a different process and does not show it): OPcache on, caches written, worker count.
     *
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'server_workers' => max(1, (int) getenv('PHP_CLI_SERVER_WORKERS')),
            'opcache' => is_array($opcache) ? [
                'enabled' => (bool) ($opcache['opcache_enabled'] ?? false),
                'cached_scripts' => (int) ($opcache['opcache_statistics']['num_cached_scripts'] ?? 0),
                'hit_rate_percent' => round((float) ($opcache['opcache_statistics']['opcache_hit_rate'] ?? 0), 1),
                'memory_used_mb' => round(((float) ($opcache['memory_usage']['used_memory'] ?? 0)) / 1048576, 1),
                'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
            ] : ['enabled' => false],
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'events_cached' => app()->eventsAreCached(),
        ];
    }
}
