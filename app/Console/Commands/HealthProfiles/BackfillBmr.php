<?php

namespace App\Console\Commands\HealthProfiles;

use App\Models\HealthProfile;
use App\Services\Nutrition\NutritionCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2, D1: fills health_profiles.bmr for profiles saved before the column
 * existed, from the weight, height, age and gender already stored. Dry run by
 * default (counts only); --apply writes. daily_calorie_needs and every other
 * column are never touched, and never run automatically.
 */
class BackfillBmr extends Command
{
    protected $signature = 'health-profiles:backfill-bmr {--apply : Write the values (default is a dry run)}';

    protected $description = 'Fill health_profiles.bmr for profiles that have none (dry run unless --apply)';

    public function handle(NutritionCalculatorService $nutrition): int
    {
        $apply = (bool) $this->option('apply');
        $missing = 0;
        $updated = 0;

        HealthProfile::query()->whereNull('bmr')->orderBy('id')->chunkById(200, function ($profiles) use ($nutrition, $apply, &$missing, &$updated) {
            foreach ($profiles as $profile) {
                $missing++;

                if ($apply) {
                    // Straight to the row: no model events, updated_at left alone.
                    DB::table('health_profiles')->where('id', $profile->id)->update(['bmr' => $nutrition->bmrForProfile($profile)]);
                    $updated++;
                }
            }
        });

        $this->info(($apply ? 'Applied' : 'Dry run').": {$missing} profile(s) without a BMR".($apply ? ", {$updated} updated." : '.'));

        if (! $apply) {
            $this->line('Nothing written. Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
