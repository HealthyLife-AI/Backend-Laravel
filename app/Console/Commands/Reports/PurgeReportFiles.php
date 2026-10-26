<?php

namespace App\Console\Commands\Reports;

use App\Services\Reports\ReportService;
use Illuminate\Console\Command;

/** Phase 4, A5: daily; removes the files of reports a patient deleted more than 30 days ago. */
class PurgeReportFiles extends Command
{
    protected $signature = 'reports:purge';

    protected $description = 'Remove the files of medical reports deleted more than the purge window ago';

    public function handle(ReportService $reports): int
    {
        $count = $reports->purgeExpired();
        $this->info("{$count} report file(s) purged.");

        return self::SUCCESS;
    }
}
