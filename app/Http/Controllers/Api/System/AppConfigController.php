<?php

namespace App\Http\Controllers\Api\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public (audit MO-06): the minimum and latest patient-app versions and the
 * store links, so the app can show a soft "update available" hint, and so you
 * can see what is configured with a plain GET.
 */
class AppConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'min_supported' => config('app_versions.min_supported'),
            'latest' => config('app_versions.latest'),
            'store_url' => config('app_versions.store_url'),
        ]);
    }
}
