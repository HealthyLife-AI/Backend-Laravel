<?php

namespace App\Console\Commands\Foods;

use App\Models\Food;
use Illuminate\Console\Command;

/**
 * Clinical batch 1, E3: the list of approved foods to send to the
 * nutritionist for GI / sodium review. Read-only.
 */
class ExportNutritionReview extends Command
{
    protected $signature = 'foods:export-nutrition-review {path : Where to write the CSV}';

    protected $description = 'Export approved foods (id, names, carbs, fibre, current GI / sodium) as CSV for nutrition review';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $out = @fopen($path, 'w');

        if ($out === false) {
            $this->error('Cannot write to that path.');

            return self::FAILURE;
        }

        // Excel opens a UTF-8 CSV with Arabic correctly only with a BOM.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['food_id', 'name_ar', 'name_en', 'carbs_g_per_100g', 'fiber_g_per_100g', 'glycemic_index', 'sodium_mg_per_100g']);

        $count = 0;

        Food::approved()->orderBy('id')->each(function (Food $food) use ($out, &$count) {
            fputcsv($out, [$food->id, $food->name_ar, $food->name_en, $food->carbs_g_per_100g, $food->fiber_g_per_100g, $food->glycemic_index, $food->sodium_mg_per_100g]);
            $count++;
        });

        fclose($out);
        $this->info("Exported {$count} approved food(s).");

        return self::SUCCESS;
    }
}
