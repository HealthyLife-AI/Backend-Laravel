<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiUsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin's AI usage and cost report for one month (default: this month). Admin role only. */
class AdminAiUsageController extends Controller
{
    public function __invoke(Request $request, AiUsageReport $report): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return response()->json($report->forMonth($data['month'] ?? null));
    }
}
