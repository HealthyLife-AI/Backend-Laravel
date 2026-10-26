<?php

namespace App\Console\Commands\Foods;

use App\Models\Food;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4, B2: loads cost tier and prep level for foods from a CSV a nutritionist
 * has reviewed (columns: food_id, cost_tier, prep_level; an empty cell = leave
 * that column alone; extra columns, like the names from the export, are ignored).
 *
 * Dry run by default; --apply writes ONLY those two columns plus the review stamp
 * (time and, with --reviewer, the user). All or nothing: one bad row and nothing
 * is written. --source names where the values come from (required; reported, not stored).
 */
class ImportFoodTags extends Command
{
    protected $signature = 'foods:import-tags {csv : Path to the reviewed CSV} {--source= : Where the values come from (required)} {--reviewer= : User id of the reviewing nutritionist} {--apply : Write the values (default is a dry run)}';

    protected $description = 'Import reviewed cost tier / prep level for foods (dry run unless --apply)';

    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        $source = trim((string) $this->option('source'));

        if ($source === '') {
            $this->error('--source is required (who or what the values come from).');

            return self::FAILURE;
        }

        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error('Cannot read the CSV file.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        $header = $header === false ? [] : array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);

        if (! in_array('food_id', $header, true) || (! in_array('cost_tier', $header, true) && ! in_array('prep_level', $header, true))) {
            fclose($handle);
            $this->error('The CSV needs the columns food_id and cost_tier and/or prep_level.');

            return self::FAILURE;
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $id = trim((string) ($row['food_id'] ?? ''));
            $cost = strtolower(trim((string) ($row['cost_tier'] ?? '')));
            $prep = strtolower(trim((string) ($row['prep_level'] ?? '')));

            if (! ctype_digit($id)) {
                $errors[] = "line {$line}: food_id '{$id}' is not a number";

                continue;
            }

            if (isset($rows[(int) $id])) {
                $errors[] = "line {$line}: food_id {$id} appears twice";

                continue;
            }

            if ($cost !== '' && ! in_array($cost, Food::COST_TIERS, true)) {
                $errors[] = "line {$line}: cost_tier '{$cost}' must be one of ".implode(', ', Food::COST_TIERS);

                continue;
            }

            if ($prep !== '' && ! in_array($prep, Food::PREP_LEVELS, true)) {
                $errors[] = "line {$line}: prep_level '{$prep}' must be one of ".implode(', ', Food::PREP_LEVELS);

                continue;
            }

            if ($cost === '' && $prep === '') {
                continue;
            }

            $rows[(int) $id] = ['cost_tier' => $cost === '' ? null : $cost, 'prep_level' => $prep === '' ? null : $prep];
        }

        fclose($handle);

        $known = Food::query()->whereIn('id', array_keys($rows))->pluck('id')->all();
        $unknown = array_values(array_diff(array_keys($rows), $known));

        foreach ($unknown as $id) {
            $errors[] = "food_id {$id} does not exist";
        }

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'Importing' : 'Dry run — would import').': '.count($rows)." food(s) from '{$source}'. Problems: ".count($errors).'.');

        foreach ($errors as $e) {
            $this->warn($e);
        }

        if ($errors !== []) {
            $this->error($apply ? 'Not applied: fix the problems above first.' : 'The CSV has problems; --apply would refuse it.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->line('Nothing written. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        $reviewer = $this->option('reviewer') !== null ? (int) $this->option('reviewer') : null;

        DB::transaction(function () use ($rows, $reviewer) {
            foreach ($rows as $id => $values) {
                $changes = array_filter($values, fn ($v) => $v !== null) + ['tags_reviewed_at' => now(), 'tags_reviewed_by' => $reviewer];

                // Straight to the row: no model events, and no other column is touched.
                DB::table('foods')->where('id', $id)->update($changes);
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
