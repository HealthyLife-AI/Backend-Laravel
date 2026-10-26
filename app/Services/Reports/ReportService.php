<?php

namespace App\Services\Reports;

use App\Exceptions\ApiCodeException;
use App\Models\Alert;
use App\Models\PatientReport;
use App\Models\ReportAccessLog;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Alerts\AlertSeverity;
use App\Services\Consent\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 4, A: the lifecycle of a medical report. Nothing here is ever sent to an
 * LLM, put in a push, a summary or an insight.
 */
class ReportService
{
    public function __construct(
        private readonly ReportCrypto $crypto,
        private readonly ImageMetadataStripper $stripper,
        private readonly ConsentService $consent,
        private readonly AlertSeverity $severity,
    ) {}

    private function disk()
    {
        return Storage::disk((string) config('medical_reports.disk'));
    }

    /** @param  array{category: string, title: string, report_date?: ?string}  $data */
    public function upload(Subscriber $subscriber, User $by, UploadedFile $file, array $data): PatientReport
    {
        if (! $this->consent->hasMedicalReportsConsent($by)) {
            throw new ApiCodeException('Consent to upload medical reports is required.', 'reports_consent_required', 403, [
                'current_version' => (string) config('medical_reports.consent.version'),
            ]);
        }

        if (PatientReport::query()->where('subscriber_id', $subscriber->id)->count() >= (int) config('medical_reports.max_files_per_patient')) {
            throw new ApiCodeException('The report limit was reached. Delete one first.', 'reports_limit_reached', 422);
        }

        if ($file->getSize() > (int) config('medical_reports.max_file_mb') * 1048576) {
            throw new ApiCodeException('The file is too large.', 'report_too_large', 422);
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $this->sniff($bytes);
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = (array) config('medical_reports.allowed_mimes');

        // The content decides, not the name: it must be an allowed type and agree with the extension.
        if ($mime === null || ! in_array($mime, $allowed, true) || ($allowed[$extension] ?? null) !== $mime) {
            throw new ApiCodeException('This file type is not allowed.', 'report_type_not_allowed', 422);
        }

        $pages = null;
        if (str_starts_with($mime, 'image/')) {
            $bytes = $this->stripper->strip($bytes, $mime);
        } else {
            $pages = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $bytes) ?: null;
        }

        $path = Str::uuid()->toString().'.enc';
        $plain = fopen('php://temp', 'w+b');
        fwrite($plain, $bytes);
        rewind($plain);
        $cipher = fopen('php://temp', 'w+b');
        $this->crypto->encryptStream($plain, $cipher);
        rewind($cipher);
        $this->disk()->put($path, $cipher);
        fclose($plain);
        fclose($cipher);

        $report = PatientReport::create([
            'subscriber_id' => $subscriber->id,
            'uploaded_by' => $by->id,
            'category' => $data['category'],
            'title' => trim($data['title']),
            'report_date' => $data['report_date'] ?? null,
            'mime' => $mime,
            'size_bytes' => strlen($bytes),
            'pages' => $pages,
            'path' => $path,
            'sha256' => hash('sha256', $bytes),
        ]);

        $subscriber->alerts()->create([
            'patient_report_id' => $report->id,
            'type' => Alert::TYPE_REPORT_UPLOADED,
            'severity' => $this->severity->for($subscriber, Alert::TYPE_REPORT_UPLOADED),
            // A fixed sentence, like every alert: nothing about the report.
            'message' => 'A patient uploaded a medical report.',
        ]);

        return $report;
    }

    /** Streams the decrypted file inline and writes the access-log row first. */
    public function stream(PatientReport $report, User $by, Request $request): StreamedResponse
    {
        if ($report->purged_at !== null || ! $this->disk()->exists($report->path)) {
            abort(404);
        }

        ReportAccessLog::create([
            'report_id' => $report->id,
            'user_id' => $by->id,
            'role' => $by->hasRole('nutritionist') ? 'nutritionist' : 'client',
            'ip' => $request->ip(),
            'accessed_at' => now(),
        ]);

        $source = $this->disk()->readStream($report->path);
        $extension = $report->mime === 'application/pdf' ? 'pdf' : explode('/', $report->mime)[1];

        return response()->stream(function () use ($source): void {
            $this->crypto->decryptStream($source, function (string $chunk): void {
                echo $chunk;
                flush();
            });
            fclose($source);
        }, 200, [
            'Content-Type' => $report->mime,
            'Content-Length' => (string) $report->size_bytes,
            'Content-Disposition' => 'inline; filename="report-'.$report->id.'.'.$extension.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The nutritionist's note and/or "reviewed": reviewing resolves exactly this report's alert. */
    public function review(PatientReport $report, User $by, ?string $note, bool $reviewed, bool $noteGiven): PatientReport
    {
        if ($noteGiven) {
            $report->nutritionist_note = $note === null || trim($note) === '' ? null : trim($note);
        }

        if ($reviewed && $report->reviewed_at === null) {
            $report->reviewed_at = now();
            $report->reviewed_by = $by->id;
        }

        $report->save();

        if ($reviewed) {
            Alert::query()->where('patient_report_id', $report->id)->open()->update(['resolved_at' => now()]);
        }

        return $report;
    }

    /** The patient's delete: soft, the file stays for the purge window. Resolves the alert. */
    public function delete(PatientReport $report): void
    {
        $report->delete();
        Alert::query()->where('patient_report_id', $report->id)->open()->update(['resolved_at' => now()]);
    }

    /** Removes the encrypted file itself (and marks the row). Safe to call twice. */
    public function purgeFile(PatientReport $report): void
    {
        if ($report->purged_at === null) {
            $this->disk()->delete($report->path);
            $report->forceFill(['purged_at' => now()])->save();
        }
    }

    /** Daily job: files of reports deleted more than the window ago. */
    public function purgeExpired(): int
    {
        $cutoff = now()->subDays((int) config('medical_reports.purge_after_days'));
        $count = 0;

        PatientReport::onlyTrashed()->whereNull('purged_at')->where('deleted_at', '<=', $cutoff)->each(function (PatientReport $r) use (&$count) {
            $this->purgeFile($r);
            $count++;
        });

        return $count;
    }

    /** Account deletion: every file of this patient, at once, before the rows cascade away. */
    public function purgeAllFor(Subscriber $subscriber): void
    {
        PatientReport::withTrashed()->where('subscriber_id', $subscriber->id)->each(fn (PatientReport $r) => $this->purgeFile($r));
    }

    /** The real type of the bytes. HEIC is recognised so it can be refused with its own message. */
    private function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'mif1', 'msf1', 'heim', 'heis'], true) => 'image/heic',
            default => null,
        };
    }
}
