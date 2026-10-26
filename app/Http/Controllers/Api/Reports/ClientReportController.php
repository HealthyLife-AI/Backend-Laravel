<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\PatientReport;
use App\Models\Subscriber;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4, A6: a patient's reports for their own nutritionist only (another
 * nutritionist's patient, or another patient's report id, is a 404).
 */
class ClientReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Subscriber $subscriber): AnonymousResourceCollection
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return ReportResource::collection(
            PatientReport::query()->where('subscriber_id', $subscriber->id)->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    public function file(Subscriber $subscriber, int $report, Request $request): StreamedResponse
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        return $this->reports->stream($this->find($subscriber, $report), Auth::user(), $request);
    }

    /** `{ nutritionist_note?: string|null, reviewed?: true }` */
    public function update(Subscriber $subscriber, int $report, Request $request): ReportResource
    {
        abort_unless($subscriber->belongsToCaller(), 404);

        $data = $request->validate([
            'nutritionist_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reviewed' => ['sometimes', 'boolean'],
        ]);

        $model = $this->find($subscriber, $report);

        return new ReportResource($this->reports->review(
            $model,
            Auth::user(),
            $data['nutritionist_note'] ?? null,
            (bool) ($data['reviewed'] ?? false),
            array_key_exists('nutritionist_note', $data),
        ));
    }

    private function find(Subscriber $subscriber, int $id): PatientReport
    {
        return PatientReport::query()->where('subscriber_id', $subscriber->id)->findOrFail($id);
    }
}
