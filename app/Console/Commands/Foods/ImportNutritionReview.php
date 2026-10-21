<?php

namespace App\Console\Commands\Foods;

use App\Models\Food;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clinical batch 1, E3: loads glycemic index and sodium for foods from a CSV
 * a nutritionist has reviewed (columns: food_id, glycemic_index,
 * sodium_mg_per_100g; either value may be empty = leave that column alone).
 *
 * The app holds no such values of its own. Dry run by default; --apply
 * writes ONLY those two columns plus the review stamp (source, time and,
 * when --reviewer is given, the user). Every other column is untouched.
 */
class ImportNutritionReview extends Command
{
    protected $signature = 'foods:import-nutrition-review {csv : Path to the reviewed CSV} {--source= : Where the values come from (required)} {--reviewer= : User id of the reviewing nutritionist} {--apply : Write the values (default is a dry run)}';

    protected $description = 'Import reviewed glycemic index / sodium values for foods (dry run unless --apply)';

    public function handle(): int
    {
        $path = (string) $this->argument('csv');
        $source = trim((string) $this->option('source'));

        if ($source === '') {
            $this->error('--source is required (the published table the values come from).');

            return self::FAILURE;
        }

        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error('Cannot read the CSV file.');

            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        $header = $header === false ? [] : array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);

        if (! in_array('food_id', $header, true) || (! in_array('glycemic_index', $header, true) && ! in_array('sodium_mg_per_100g', $header, true))) {
            fclose($handle);
            $this->error('The CSV needs the columns food_id, glycemic_index and/or sodium_mg_per_100g.');

            return self::FAILURE;
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $id = trim((string) ($row['food_id'] ?? ''));
            $gi = trim((string) ($row['glycemic_index'] ?? ''));
            $sodium = trim((string) ($row['sodium_mg_per_100g'] ?? ''));

            if (! ctype_digit($id)) {
                $errors[] = "line {$line}: food_id '{$id}' is not a number";

                continue;
            }

            if ($gi !== '' && (! ctype_digit($gi) || (int) $gi > 110)) {
                $errors[] = "line {$line}: glycemic_index '{$gi}' must be a whole number 0-110";

                continue;
            }

            if ($sodium !== '' && (! is_numeric($sodium) || (float) $sodium < 0 || (float) $sodium > 40000)) {
                $errors[] = "line {$line}: sodium_mg_per_100g '{$sodium}' must be a number 0-40000";

                continue;
            }

            if ($gi === '' && $sodium === '') {
                continue;
            }

            $rows[(int) $id] = ['gi' => $gi === '' ? null : (int) $gi, 'sodium' => $sodium === '' ? null : round((float) $sodium, 1)];
        }

        fclose($handle);

        $known = Food::query()->whereIn('id', array_keys($rows))->pluck('id')->all();
        $unknown = array_values(array_diff(array_keys($rows), $known));
        $valid = array_intersect_key($rows, array_flip($known));

        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'Importing' : 'Dry run — would import').': '.count($valid)." food(s) from '{$source}'.");
        $this->line('Rows with a problem: '.count($errors).'. Unknown food ids: '.count($unknown).'.');

        foreach ($errors as $e) {
            $this->warn($e);
        }

        if ($unknown !== []) {
            $this->warn('Unknown food ids (skipped): '.implode(', ', array_slice($unknown, 0, 50)).(count($unknown) > 50 ? ' …' : ''));
        }

        if (! $apply) {
            $this->line('Nothing written. Re-run with --apply to write.');

            return $errors === [] && $unknown === [] ? self::SUCCESS : self::FAILURE;
        }

        // Refuse a partial import: fix the CSV and run it again.
        if ($errors !== [] || $unknown !== []) {
            $this->error('Not applied: fix the problems above first.');

            return self::FAILURE;
        }

        $reviewer = $this->option('reviewer') !== null ? (int) $this->option('reviewer') : null;

        DB::transaction(function () use ($valid, $source, $reviewer) {
            foreach ($valid as $id => $values) {
                $changes = ['nutrition_review_source' => $source, 'nutrition_reviewed_at' => now(), 'nutrition_reviewed_by' => $reviewer];

                if ($values['gi'] !== null) {
                    $changes['glycemic_index'] = $values['gi'];
                }

                if ($values['sodium'] !== null) {
                    $changes['sodium_mg_per_100g'] = $values['sodium'];
                }

                // Straight to the row: no model events (the Arabic search
                // column and the tagger have nothing to do with this).
                DB::table('foods')->where('id', $id)->update($changes);
            }
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
