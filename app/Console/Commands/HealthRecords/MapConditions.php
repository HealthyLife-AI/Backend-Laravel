<?php

namespace App\Console\Commands\HealthRecords;

use App\Models\HealthProfile;
use App\Models\PatientCondition;
use App\Services\HealthRecords\HealthRecordService;
use App\Support\ConditionCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clinical batch 1, C4: turns the free-text health_conditions list into
 * structured patient_conditions, by keyword. Anything that does not match
 * becomes code "other" with the original text, so nothing is lost.
 *
 * Dry run by default; --apply writes. Never runs automatically. A patient
 * who already has structured conditions is left alone. The report lists
 * subscriber ids and codes only: no names, no phones.
 */
class MapConditions extends Command
{
    protected $signature = 'health-records:map-conditions {--apply : Write the mapping (default is a dry run)}';

    protected $description = 'Map the free-text health_conditions list to structured conditions (dry run unless --apply)';

    public function handle(HealthRecordService $records): int
    {
        $apply = (bool) $this->option('apply');
        $plan = [];
        $skipped = 0;

        HealthProfile::query()->orderBy('id')->each(function (HealthProfile $profile) use (&$plan, &$skipped) {
            $texts = array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : '', $profile->health_conditions ?? [])));

            if ($texts === []) {
                return;
            }

            if (PatientCondition::query()->where('subscriber_id', $profile->subscriber_id)->exists()) {
                $skipped++;

                return;
            }

            $mapped = [];
            foreach ($texts as $text) {
                $m = ConditionCodes::fromText($text);
                $mapped[$m['code'] === 'other' ? 'other:'.mb_strtolower((string) $m['other_text']) : $m['code']] = $m;
            }

            $plan[$profile->subscriber_id] = array_values($mapped);
        });

        $counts = [];
        foreach ($plan as $items) {
            foreach ($items as $m) {
                $counts[$m['code']] = ($counts[$m['code']] ?? 0) + 1;
            }
        }
        ksort($counts);

        $this->info(($apply ? 'Mapping' : 'Dry run — would map').': '.count($plan).' patient(s); already structured, left alone: '.$skipped);
        $this->table(['code', 'rows'], collect($counts)->map(fn ($n, $code) => [$code, $n])->values()->all());
        $this->table(['subscriber id', 'codes'], collect($plan)->map(fn ($items, $id) => [$id, implode(', ', array_map(fn ($m) => $m['code'], $items))])->values()->all());

        if (! $apply) {
            $this->line('Nothing written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $records) {
            foreach ($plan as $subscriberId => $items) {
                foreach ($items as $m) {
                    PatientCondition::create(['subscriber_id' => $subscriberId, 'code' => $m['code'], 'other_text' => $m['other_text']]);
                }
                $records->mirrorConditions((int) $subscriberId);
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
