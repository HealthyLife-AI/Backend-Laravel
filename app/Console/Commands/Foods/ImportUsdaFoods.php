<?php

namespace App\Console\Commands\Foods;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * S2-07 / FR-23: import the USDA SR Legacy dataset (the "final release of
 * the Standard Reference data type" per USDA — ~7,800 generic
 * ingredients, the right base for a plan designer; NOT the 300k+-item
 * Branded Foods set, which is packaged retail products). Source:
 * https://fdc.nal.usda.gov/fdc-datasets/FoodData_Central_sr_legacy_food_csv_2018-04.zip
 *
 * Not committed to the repo (36MB uncompressed) — download and unzip it
 * to `storage/app/imports/usda/` (or pass --path), then run this command.
 * Streams both source CSVs (fopen/fgetcsv) and batches upserts rather
 * than loading either file fully into memory or the DB.
 */
class ImportUsdaFoods extends Command
{
    protected $signature = 'foods:import-usda
        {--path= : Directory containing food.csv and food_nutrient.csv (default: storage/app/imports/usda)}
        {--chunk=500 : Rows per upsert batch}';

    protected $description = 'Import the USDA SR Legacy food dataset as approved, admin-sourced foods';

    /** USDA's own stable nutrient numbers — see nutrient.csv in the dataset. */
    private const NUTRIENT_ENERGY_KCAL = 1008;

    private const NUTRIENT_PROTEIN_G = 1003;

    private const NUTRIENT_FAT_G = 1004;

    private const NUTRIENT_CARBS_G = 1005;

    private const NUTRIENT_FIBER_G = 1079;

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/imports/usda');
        $foodCsv = "{$path}/food.csv";
        $nutrientCsv = "{$path}/food_nutrient.csv";

        if (! is_file($foodCsv) || ! is_file($nutrientCsv)) {
            $this->error("Expected food.csv and food_nutrient.csv in {$path}");
            $this->line('Download: https://fdc.nal.usda.gov/fdc-datasets/FoodData_Central_sr_legacy_food_csv_2018-04.zip');

            return self::FAILURE;
        }

        $this->info('Reading nutrient amounts...');
        $nutrientsByFood = $this->readTargetNutrients($nutrientCsv);
        $this->info(sprintf('Loaded nutrient data for %d foods.', count($nutrientsByFood)));

        $this->info('Importing foods...');
        [$imported, $skipped] = $this->importFoods($foodCsv, $nutrientsByFood, (int) $this->option('chunk'));

        $this->info("Imported/updated {$imported} foods. Skipped {$skipped} with no energy value.");

        return self::SUCCESS;
    }

    /**
     * One pass over the (36MB) food_nutrient.csv, keeping only the 5
     * macro rows per food — the rest of that file's columns (derivation,
     * min/max/median, footnotes) aren't needed for a per-100g macro
     * table, so they're never loaded.
     *
     * @return array<int, array<int, float>> fdc_id => [nutrient_id => amount]
     */
    private function readTargetNutrients(string $path): array
    {
        $targetIds = [
            self::NUTRIENT_ENERGY_KCAL => true,
            self::NUTRIENT_PROTEIN_G => true,
            self::NUTRIENT_FAT_G => true,
            self::NUTRIENT_CARBS_G => true,
            self::NUTRIENT_FIBER_G => true,
        ];

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $col = array_flip($header);

        $result = [];

        while (($row = fgetcsv($handle)) !== false) {
            $nutrientId = (int) $row[$col['nutrient_id']];

            if (! isset($targetIds[$nutrientId])) {
                continue;
            }

            $fdcId = (int) $row[$col['fdc_id']];
            $result[$fdcId][$nutrientId] = (float) $row[$col['amount']];
        }

        fclose($handle);

        return $result;
    }

    /**
     * @param  array<int, array<int, float>>  $nutrientsByFood
     * @return array{0: int, 1: int} [imported, skipped]
     */
    private function importFoods(string $path, array $nutrientsByFood, int $chunkSize): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $col = array_flip($header);

        $batch = [];
        $imported = 0;
        $skipped = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $fdcId = (int) $row[$col['fdc_id']];
            $nutrients = $nutrientsByFood[$fdcId] ?? [];

            // No energy value means the row is unusable for plan design
            // (every meal calculation needs calories) — skip rather than
            // import a food that would silently break totals.
            if (! isset($nutrients[self::NUTRIENT_ENERGY_KCAL])) {
                $skipped++;

                continue;
            }

            $batch[] = [
                'source' => 'usda',
                'status' => 'approved',
                'name_en' => mb_substr(trim($row[$col['description']]), 0, 255),
                'usda_fdc_id' => $fdcId,
                'calories_per_100g' => $nutrients[self::NUTRIENT_ENERGY_KCAL],
                'protein_g_per_100g' => $nutrients[self::NUTRIENT_PROTEIN_G] ?? 0,
                'carbs_g_per_100g' => $nutrients[self::NUTRIENT_CARBS_G] ?? 0,
                'fat_g_per_100g' => $nutrients[self::NUTRIENT_FAT_G] ?? 0,
                'fiber_g_per_100g' => $nutrients[self::NUTRIENT_FIBER_G] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= $chunkSize) {
                $imported += $this->upsertBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $imported += $this->upsertBatch($batch);
        }

        fclose($handle);

        return [$imported, $skipped];
    }

    /** @param  list<array<string, mixed>>  $batch */
    private function upsertBatch(array $batch): int
    {
        DB::table('foods')->upsert(
            $batch,
            uniqueBy: ['usda_fdc_id'],
            update: ['name_en', 'calories_per_100g', 'protein_g_per_100g', 'carbs_g_per_100g', 'fat_g_per_100g', 'fiber_g_per_100g', 'updated_at'],
        );

        return count($batch);
    }
}
