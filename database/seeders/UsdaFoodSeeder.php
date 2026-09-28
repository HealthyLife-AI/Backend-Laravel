<?php

namespace Database\Seeders;

use App\Models\Food;
use Illuminate\Database\Seeder;

/**
 * FR-23: the full generic-ingredient catalog, shipped with the repo.
 *
 * `database/data/usda_sr_legacy_foods.csv` is USDA FoodData Central's SR
 * Legacy release reduced to the five macros this app uses (7,793 foods,
 * ~690KB) — built from the same source `foods:import-usda` reads, so a
 * deploy gets the whole catalog from `db:seed` without anyone downloading
 * the 36MB dataset. `usda_arabic_names.csv` adds Arabic names for the
 * common everyday ingredients, matched to USDA rows by fdc_id so their
 * macros stay USDA's own numbers.
 *
 * Safe to run any number of times (`php artisan db:seed --force` after a
 * deploy — nothing runs it automatically, see DEPLOYMENT.md): it only
 * INSERTS fdc_ids that have no row yet and only FILLS an Arabic name
 * that's still empty, so an admin's later edit is never overwritten.
 */
class UsdaFoodSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(): void
    {
        $existing = Food::whereNotNull('usda_fdc_id')->pluck('usda_fdc_id')->flip();
        $now = now();
        $batch = [];

        foreach ($this->rows(database_path('data/usda_sr_legacy_foods.csv')) as $row) {
            if (isset($existing[(int) $row['fdc_id']])) {
                continue;
            }

            $batch[] = [
                'source' => 'usda',
                'status' => 'approved',
                'usda_fdc_id' => (int) $row['fdc_id'],
                'name_en' => $row['name_en'],
                'calories_per_100g' => (float) $row['calories'],
                'protein_g_per_100g' => (float) $row['protein_g'],
                'carbs_g_per_100g' => (float) $row['carbs_g'],
                'fat_g_per_100g' => (float) $row['fat_g'],
                'fiber_g_per_100g' => $row['fiber_g'] === '' ? null : (float) $row['fiber_g'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) === self::CHUNK) {
                Food::insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            Food::insert($batch);
        }

        foreach ($this->rows(database_path('data/usda_arabic_names.csv')) as $row) {
            Food::where('usda_fdc_id', (int) $row['fdc_id'])
                ->whereNull('name_ar')
                ->update(['name_ar' => $row['name_ar']]);
        }
    }

    /** @return \Generator<int, array<string, string>> */
    private function rows(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        while (($values = fgetcsv($handle)) !== false) {
            yield array_combine($header, $values);
        }

        fclose($handle);
    }
}
