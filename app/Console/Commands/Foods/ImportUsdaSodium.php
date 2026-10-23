<?php

namespace App\Console\Commands\Foods;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills foods.sodium_mg_per_100g from the USDA SR Legacy download (nutrient
 * 1093, sodium, mg per 100 g) for foods that carry a usda_fdc_id. Seeded
 * local dishes (no usda_fdc_id) are never touched.
 *
 * Dry run by default; --apply writes. Only NULL sodium is filled unless
 * --overwrite is given. Calories, macros, GI, names, allergens and status
 * are never changed; the only other columns written are the review stamp
 * (see reviewSource()), so a GI review already on a food is kept.
 *
 * food_nutrient.csv is streamed row by row; only the (fdc_id => sodium)
 * pairs are kept, never the file.
 */
class ImportUsdaSodium extends Command
{
    /** USDA's own stable nutrient number for sodium (Na), mg per 100 g. */
    public const NUTRIENT_SODIUM_MG = 1093;

    public const SOURCE = 'USDA SR Legacy 2018 (sodium)';

    private const MAX_SOURCE_LENGTH = 255;

    protected $signature = 'foods:import-usda-sodium
        {--path= : Directory containing food_nutrient.csv (default: storage/app/imports/usda)}
        {--apply : Write the values (default is a dry run)}
        {--overwrite : Also replace sodium that is already set (default fills only NULL)}
        {--chunk=500 : Foods per transaction}';

    protected $description = 'Fill foods.sodium_mg_per_100g from the USDA SR Legacy food_nutrient.csv (dry run unless --apply)';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/imports/usda');
        $nutrientCsv = "{$path}/food_nutrient.csv";

        if (! is_file($nutrientCsv)) {
            $this->error("Expected food_nutrient.csv in {$path}");
            $this->line('Download: https://fdc.nal.usda.gov/fdc-datasets/FoodData_Central_sr_legacy_food_csv_2018-04.zip');

            return self::FAILURE;
        }

        [$sodiumByFdc, $outOfRange] = $this->readSodium($nutrientCsv);

        $apply = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $matched = 0;
        $alreadyHad = 0;
        $toUpdate = 0;
        $updated = 0;
        $matchedFdc = [];
        $top = [];

        foreach (array_chunk(array_keys($sodiumByFdc), $chunkSize) as $fdcIds) {
            $foods = DB::table('foods')
                ->whereIn('usda_fdc_id', $fdcIds)
                ->select('id', 'usda_fdc_id', 'name_ar', 'name_en', 'sodium_mg_per_100g', 'nutrition_review_source', 'nutrition_reviewed_at')
                ->get();

            $changes = [];

            foreach ($foods as $food) {
                $value = $sodiumByFdc[(int) $food->usda_fdc_id];
                $matched++;
                $matchedFdc[(int) $food->usda_fdc_id] = true;
                $top[] = ['id' => $food->id, 'name' => $food->name_ar ?: $food->name_en, 'mg' => $value];
                $top = $this->keepTop($top, 15);

                if ($food->sodium_mg_per_100g !== null) {
                    $alreadyHad++;

                    if (! $overwrite || abs((float) $food->sodium_mg_per_100g - $value) < 0.05) {
                        continue;
                    }
                }

                $toUpdate++;
                $changes[] = [$food, $value];
            }

            if ($apply && $changes !== []) {
                DB::transaction(function () use ($changes) {
                    foreach ($changes as [$food, $value]) {
                        DB::table('foods')->where('id', $food->id)->update([
                            'sodium_mg_per_100g' => $value,
                            // Keep an existing review time (a GI review); stamp it only when there was none.
                            'nutrition_reviewed_at' => $food->nutrition_reviewed_at ?? now(),
                            'nutrition_review_source' => self::reviewSource($food->nutrition_review_source),
                        ]);
                    }
                });
                $updated += count($changes);
            }
        }

        $this->info(($apply ? 'Applied' : 'Dry run').' — USDA sodium (nutrient '.self::NUTRIENT_SODIUM_MG.')');
        $this->table(['', 'foods'], [
            ['foods matched', $matched],
            [$apply ? 'updated' : 'would update', $apply ? $updated : $toUpdate],
            ['already had sodium'.($overwrite ? ' (replaced)' : ' (left as is)'), $alreadyHad],
            ['out of range (skipped)', $outOfRange],
            ['fdc ids in CSV with no matching food', count($sodiumByFdc) - count($matchedFdc)],
        ]);

        $this->line('Highest sodium among matched foods:');
        $this->table(['id', 'food', 'mg / 100 g'], array_map(fn ($t) => [$t['id'], $t['name'], $t['mg']], $top));

        if (! $apply) {
            $this->line('Nothing written. Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }

    /**
     * The review source after a sodium import: the USDA label when there was
     * none, left alone when it already names USDA, otherwise appended to the
     * existing source (a GI review keeps its own text). Never longer than the
     * column: the existing text is shortened, the suffix is kept whole.
     */
    public static function reviewSource(?string $existing): string
    {
        $existing = trim((string) $existing);

        if ($existing === '') {
            return self::SOURCE;
        }

        if (str_contains($existing, 'USDA')) {
            return mb_substr($existing, 0, self::MAX_SOURCE_LENGTH);
        }

        $suffix = ' + '.self::SOURCE;

        return mb_substr($existing, 0, self::MAX_SOURCE_LENGTH - mb_strlen($suffix)).$suffix;
    }

    /**
     * One streamed pass: the sodium row of each food, 0-40000 only.
     *
     * @return array{0: array<int, float>, 1: int} [fdc_id => mg per 100 g, rows out of range]
     */
    private function readSodium(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $col = array_flip($header ?: []);
        $result = [];
        $outOfRange = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ((int) ($row[$col['nutrient_id']] ?? 0) !== self::NUTRIENT_SODIUM_MG) {
                continue;
            }

            $amount = $row[$col['amount']] ?? '';

            if ($amount === '' || ! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 40000) {
                $outOfRange++;

                continue;
            }

            $result[(int) $row[$col['fdc_id']]] = round((float) $amount, 1);
        }

        fclose($handle);

        return [$result, $outOfRange];
    }

    /**
     * @param  list<array{id: int, name: ?string, mg: float}>  $top
     * @return list<array{id: int, name: ?string, mg: float}>
     */
    private function keepTop(array $top, int $limit): array
    {
        usort($top, fn ($a, $b) => $b['mg'] <=> $a['mg']);

        return array_slice($top, 0, $limit);
    }
}
