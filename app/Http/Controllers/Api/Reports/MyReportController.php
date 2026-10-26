<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\PatientReport;
use App\Models\Subscriber;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4, A5: the patient's own medical reports (behind the feature flag). A
 * report that is not the caller's own is a 404.
 */
class MyReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(): AnonymousResourceCollection
    {
        return ReportResource::collection(
            PatientReport::query()->where('subscriber_id', $this->subscriber()->id)->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $max = (int) config('medical_reports.max_file_mb') * 1024;

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$max],
            'category' => ['required', Rule::in((array) config('medical_reports.categories'))],
            'title' => ['required', 'string', 'max:120'],
            'report_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $report = $this->reports->upload($this->subscriber(), Auth::user(), $request->file('file'), $data);

        return (new ReportResource($report))->response()->setStatusCode(201);
    }

    public function file(Request $request, int $id): StreamedResponse
    {
        return $this->reports->stream($this->own($id), Auth::user(), $request);
    }

    public function destroy(int $id): Response
    {
        $this->reports->delete($this->own($id));

        return response()->noContent();
    }

    private function own(int $id): PatientReport
    {
        return PatientReport::query()->where('subscriber_id', $this->subscriber()->id)->findOrFail($id);
    }

    private function subscriber(): Subscriber
    {
        $subscriber = Auth::user()->subscriberProfile;

        abort_if($subscriber === null, 403, 'This account is not set up as a client.');

        return $subscriber;
    }
}
