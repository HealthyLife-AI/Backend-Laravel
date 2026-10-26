<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Phase 4, A1: with the feature flag off every report endpoint is a 404, as if it did not exist. */
class EnsureMedicalReportsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('medical_reports.enabled'), 404);

        return $next($request);
    }
}
