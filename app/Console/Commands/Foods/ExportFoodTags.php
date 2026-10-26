<?php

namespace App\Console\Commands\Foods;

use App\Models\Food;
use Illuminate\Console\Command;

/**
 * Phase 4, B2: the approved foods, with their current cost tier and prep level,
 * for a nutritionist to fill in. Read-only. Pair with foods:import-tags.
 */
class ExportFoodTags extends Command
{
    protected $signature = 'foods:export-tags {path : Where to write the CSV}';

    protected $description = 'Export approved foods (id, names, category, current cost tier / prep level) as CSV for tagging';

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
        fputcsv($out, ['food_id', 'name_ar', 'name_en', 'category', 'cost_tier', 'prep_level']);

        $count = 0;

        Food::approved()->orderBy('id')->each(function (Food $food) use ($out, &$count) {
            fputcsv($out, [$food->id, $food->name_ar, $food->name_en, $food->shopping_section, $food->cost_tier, $food->prep_level]);
            $count++;
        });

        fclose($out);
        $this->info("Exported {$count} approved food(s). Fill cost_tier (".implode(', ', Food::COST_TIERS).') and prep_level ('.implode(', ', Food::PREP_LEVELS).'); an empty cell leaves the food as it is.');

        return self::SUCCESS;
    }
}
