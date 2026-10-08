<?php

namespace App\Console\Commands\HealthRecords;

use App\Models\PatientGoal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * B12: until this release POST /clients saved every new patient's
 * structured goal as 'other' (a Stringable never matched in array_search).
 * The legacy subscribers.goal was right, so the chosen goal can be
 * recovered from it — for rows nobody has edited since (updated_at =
 * created_at). Rows edited since are only listed, for a person to check.
 *
 * Dry run by default; --apply writes. --before limits it to goals created
 * before this release went live (default: now — pass the deploy time so a
 * patient added afterwards with a real 'other' is never touched).
 */
class FixInitialGoals extends Command
{
    protected $signature = 'health-records:fix-initial-goals {--apply : Write the changes (default is a dry run)} {--before= : Only goals created before this time (deploy time, e.g. "2026-10-09 10:00", app timezone)}';

    protected $description = "Recover the goal chosen when a patient was added, saved as 'other' by mistake (B12)";

    private const FROM_LEGACY = [
        'weight_loss' => 'weight_loss',
        'weight_gain' => 'weight_gain',
        'weight_maintenance' => 'weight_maintenance',
        'health_monitoring' => 'health_energy',
    ];

    public function handle(): int
    {
        $before = $this->option('before') ?: now()->toDateTimeString();

        $rows = DB::table('patient_goals')
            ->join('subscribers', 'subscribers.id', '=', 'patient_goals.subscriber_id')
            ->where('patient_goals.goal_type', 'other')
            ->where('patient_goals.created_at', '<', $before)
            ->select('patient_goals.id', 'patient_goals.subscriber_id', 'subscribers.code', 'subscribers.goal', 'patient_goals.created_at', 'patient_goals.updated_at')
            ->orderBy('patient_goals.id')
            ->get();

        $fix = $rows->filter(fn ($r) => $r->updated_at === $r->created_at && isset(self::FROM_LEGACY[$r->goal]));
        $review = $rows->reject(fn ($r) => $fix->contains('id', $r->id));

        $this->info(($this->option('apply') ? 'Fixing' : 'Dry run — would fix').": {$fix->count()} patient goal(s).");
        $this->table(['patient', 'legacy goal', 'goal_type now', 'goal_type after', 'created'], $fix->map(fn ($r) => [$r->code, $r->goal, 'other', self::FROM_LEGACY[$r->goal], $r->created_at])->all());

        if ($review->isNotEmpty()) {
            $this->warn("Edited since, or no legacy goal to read — left as they are, check by hand: {$review->count()}");
            $this->table(['patient', 'legacy goal', 'created', 'updated'], $review->map(fn ($r) => [$r->code, $r->goal, $r->created_at, $r->updated_at])->all());
        }

        if ($this->option('apply')) {
            DB::transaction(function () use ($fix) {
                foreach ($fix as $r) {
                    // Only if still untouched at the moment of writing.
                    PatientGoal::query()->whereKey($r->id)->where('goal_type', 'other')->where('updated_at', $r->updated_at)
                        ->update(['goal_type' => self::FROM_LEGACY[$r->goal]]);
                }
            });
        } else {
            $this->line('Nothing written. Run again with --apply to fix the rows above.');
        }

        return self::SUCCESS;
    }
}
