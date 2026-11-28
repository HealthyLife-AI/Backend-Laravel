<?php

namespace App\Console\Commands\Foods;

use App\Models\Food;
use App\Support\Allergens\AllergenMatcher;
use App\Support\FoodTagger;
use Illuminate\Console\Command;

/**
 * Adds missing allergen tags to existing foods (audit A-01).
 *
 * A food gets every group from its tagging rules (FoodTagger: curated CSV,
 * USDA category, name keywords) plus every group whose keyword appears in
 * its Arabic or English name (config/allergens.php). Tags are only ever
 * ADDED: a group a food already has is never removed, so a hand-checked tag
 * survives and running it twice changes nothing the second time.
 *
 * Dry run by default: it prints what it would add and writes nothing.
 * --apply writes `allergens` (and the shopping section of a food that had
 * never been tagged); no other column, no timestamps, no model events.
 */
class TagAllergens extends Command
{
    protected $signature = 'foods:tag-allergens {--apply : Write the tags (default is a dry run that changes nothing)}';

    protected $description = 'Add missing allergen tags to foods from config/allergens.php (dry run unless --apply)';

    public function handle(FoodTagger $tagger, AllergenMatcher $matcher): int
    {
        $apply = (bool) $this->option('apply');

        /** @var array<string, array{scanned: int, untagged: int, changed: int}> $bySource */
        $bySource = [];
        /** @var array<string, array<string, int>> $added group => source => foods that gain it */
        $added = [];

        Food::query()->orderBy('id')->chunkById(500, function ($foods) use ($apply, $tagger, $matcher, &$bySource, &$added) {
            foreach ($foods as $food) {
                $source = (string) ($food->source ?? 'unknown');
                $bySource[$source] ??= ['scanned' => 0, 'untagged' => 0, 'changed' => 0];
                $bySource[$source]['scanned']++;

                $current = $food->allergens;
                $wasUntagged = $current === null;
                $current ??= [];

                $rules = $tagger->tagsFor($food);
                $target = array_values(array_unique(array_merge(
                    $current,
                    $rules['allergens'],
                    $matcher->groupsInName($food->name_en.' '.$food->name_ar),
                )));
                sort($target);

                $new = array_values(array_diff($target, $current));

                if ($wasUntagged) {
                    $bySource[$source]['untagged']++;
                }

                if ($new === [] && ! $wasUntagged) {
                    continue;
                }

                $bySource[$source]['changed']++;
                foreach ($new as $group) {
                    $added[$group][$source] = ($added[$group][$source] ?? 0) + 1;
                }

                if ($apply) {
                    $columns = ['allergens' => $target];
                    if ($wasUntagged) {
                        $columns['shopping_section'] = $rules['section'];
                    }
                    $food->forceFill($columns)->saveQuietly();
                }
            }
        });

        $sources = array_keys($bySource);
        sort($sources);

        $this->line($apply ? 'APPLIED: allergen tags written.' : 'DRY RUN: nothing was written. Run again with --apply to write these tags.');
        $this->newLine();

        $this->table(
            ['source', 'foods', 'untagged before', 'foods changed'],
            array_map(fn (string $s) => [$s, $bySource[$s]['scanned'], $bySource[$s]['untagged'], $bySource[$s]['changed']], $sources),
        );

        $groups = array_keys($added);
        sort($groups);

        $this->table(
            array_merge(['allergen group (foods gaining it)'], $sources, ['total']),
            array_map(fn (string $g) => array_merge(
                [$g],
                array_map(fn (string $s) => $added[$g][$s] ?? 0, $sources),
                [array_sum($added[$g])],
            ), $groups),
        );

        $changed = array_sum(array_column($bySource, 'changed'));
        $this->info(($apply ? 'Foods changed: ' : 'Foods that would change: ').$changed.'. Tags are only ever added, never removed.');

        return self::SUCCESS;
    }
}
