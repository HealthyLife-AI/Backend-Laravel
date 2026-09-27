<?php

namespace App\Http\Middleware;

use App\Services\Scheduling\SelfScheduler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs any overdue scheduled job (alerts, reminders, weekly summaries)
 * after the response has been sent — see `SelfScheduler`. The request
 * itself never waits on it.
 */
class RunOverdueScheduledTasks
{
    public function __construct(private readonly SelfScheduler $scheduler) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('scheduling.self_trigger')) {
            // `always`: Laravel otherwise skips deferred callbacks when the
            // response is 4xx/5xx — a request that fails auth or validation
            // should still keep the schedule moving.
            defer(fn () => $this->scheduler->runOverdue(), 'self-scheduler', always: true);
        }

        return $next($request);
    }
}
