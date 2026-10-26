<?php

namespace App\Http\Resources;

use App\Models\PatientReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 4, A: a report's metadata. Never a path, a hash or a URL: the file is
 * only reachable through the authenticated stream endpoints.
 *
 * @mixin PatientReport
 */
class ReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->title,
            'report_date' => $this->report_date?->toDateString(),
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            'pages' => $this->pages,
            'nutritionist_note' => $this->nutritionist_note,
            'reviewed' => $this->reviewed_at !== null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
