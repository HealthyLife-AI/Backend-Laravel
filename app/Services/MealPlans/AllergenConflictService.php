<?php

namespace App\Services\MealPlans;

use App\Models\Food;
use App\Models\MealPlan;
use App\Models\PatientAllergy;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
use App\Support\Allergens\AllergenMatcher;
use App\Support\ArabicText;

/**
 * Pilot feedback 2, A: which foods in a plan conflict with what the
 * patient is APPROVED to be allergic to.
 *
 * Only approved allergies count (HealthRecordService::allergyGroups and
 * ::otherAllergyTexts, the same sets the AI draft excludes by). A patient's
 * own proposal does not: it has not been reviewed, and the existing
 * `pending_allergy_proposal` warning already says so.
 *
 * Two kinds of match, both through the one AllergenMatcher and the one
 * keyword list (config/allergens.php), for EVERY caller: activation, plan
 * edits, the dashboard's inline check and both AI draft paths (audit A-03):
 *  - "tag": the food's groups intersect an approved group. A food's groups
 *    are its stored `allergens` PLUS the groups whose keywords appear in its
 *    Arabic or English name, so an untagged or under-tagged food is still
 *    caught. A free-text allergy that names a group ("حليب", "peanuts")
 *    also counts as that group.
 *  - "text": an approved "other" allergy's free text is found in the food's
 *    name word by word (article, prefixes and plurals folded), or as a plain
 *    substring as before. A name match is a hint, not a proof, which is why
 *    `match` travels with every conflict.
 *
 * Leans towards matching: a false positive costs one acknowledgement.
 *
 * A food whose `allergens` is NULL has never been tagged. That is not "no
 * allergens"; it is "unknown", and is listed separately as unverified so
 * the nutritionist is not told a food is safe on no evidence.
 *
 * This only REPORTS. Whether a conflict stops an activation is decided by
 * the controller (confirmed allergies need an explicit acknowledgement;
 * intolerances and foods to avoid never block).
 */
class AllergenConflictService
{
    /** Strongest first: an item matching a confirmed allergy and an "avoid" is reported as the former. */
    private const SEVERITY_RANK = ['avoid' => 1, 'intolerance' => 2, 'confirmed_allergy' => 3];

    public const CONFIRMED = 'confirmed_allergy';

    public function __construct(
        private readonly HealthRecordService $records,
        private readonly AllergenMatcher $matcher,
    ) {}

    /**
     * What the patient is approved to avoid, loaded ONCE and reused for
     * every plan or food checked in the same request.
     *
     * @return array{groups: array<string, string>, texts: array<string, string>}
     *                                                                            groups: group => strongest class; texts: normalised other-text => strongest class
     */
    public function profile(Subscriber $subscriber): array
    {
        $rows = PatientAllergy::query()->where('subscriber_id', $subscriber->id)->get(['group', 'other_text', 'class']);

        $groups = [];
        foreach ($this->records->allergyGroups($subscriber) as $group) {
            $groups[$group] = $this->strongest($rows->where('group', $group)->pluck('class')->all());
        }

        $texts = [];
        foreach ($this->records->otherAllergyTexts($subscriber) as $text) {
            $needle = $this->fold($text);

            if ($needle === '') {
                continue;
            }

            $classes = $rows->where('group', 'other')->filter(fn ($r) => $this->fold((string) $r->other_text) === $needle)->pluck('class')->all();
            $texts[$needle] = $this->strongest($classes);
        }

        return $this->withImpliedGroups(['groups' => $groups, 'texts' => $texts]);
    }

    /**
     * The profile plus extra free-text allergies at one class: the AI draft
     * adds the legacy `health_profiles.allergies` names this way, so it never
     * excludes less than it did before.
     *
     * @param  array{groups: array<string, string>, texts: array<string, string>}  $profile
     * @param  list<string>  $texts
     * @return array{groups: array<string, string>, texts: array<string, string>}
     */
    public function withTexts(array $profile, array $texts, string $class = self::CONFIRMED): array
    {
        foreach ($texts as $text) {
            $needle = $this->fold((string) $text);
            if ($needle !== '') {
                $profile['texts'][$needle] = $this->strongest(array_filter([$profile['texts'][$needle] ?? null, $class]));
            }
        }

        return $this->withImpliedGroups($profile);
    }

    /**
     * A free-text allergy that names a group ("حليب", "peanuts") counts as
     * that group too, at the text's class, so a tagged food is caught even
     * when its name doesn't contain the word the nutritionist typed.
     *
     * @param  array{groups: array<string, string>, texts: array<string, string>}  $profile
     * @return array{groups: array<string, string>, texts: array<string, string>}
     */
    private function withImpliedGroups(array $profile): array
    {
        foreach ($profile['texts'] as $needle => $class) {
            foreach ($this->matcher->groupsInName((string) $needle) as $group) {
                $profile['groups'][$group] = $this->strongest(array_filter([$profile['groups'][$group] ?? null, $class]));
            }
        }

        return $profile;
    }

    /**
     * A food's allergen groups: what is stored plus what its name shows.
     *
     * @return list<string>
     */
    public function groupsOf(Food $food): array
    {
        return array_values(array_unique(array_merge(
            $food->allergens ?? [],
            $this->matcher->groupsInName($food->name_en.' '.$food->name_ar),
        )));
    }

    /**
     * @param  array{groups: array<string, string>, texts: array<string, string>}|null  $profile  pass one already built to avoid re-querying
     * @return array{conflicts: list<array<string, mixed>>, unverified_items: list<array<string, mixed>>}
     */
    public function forPlan(MealPlan $plan, Subscriber $subscriber, ?array $profile = null): array
    {
        $profile ??= $this->profile($subscriber);
        $plan->loadMissing('meals.items.food');

        $conflicts = [];
        $unverified = [];

        foreach ($plan->meals as $meal) {
            // `items` holds planned items AND alternatives (parent_item_id set).
            foreach ($meal->items as $item) {
                $food = $item->food;

                if ($food === null) {
                    continue;
                }

                $match = $this->matchFood($food, $profile);

                if ($match !== null) {
                    $conflicts[] = [
                        'meal_id' => $meal->id,
                        'meal_name' => $meal->name,
                        'day_index' => $meal->day_index,
                        'item_id' => $item->id,
                        'parent_item_id' => $item->parent_item_id,
                        'food_id' => $food->id,
                        'food_name_ar' => $food->name_ar ?? $food->name_en,
                        'groups' => $match['groups'],
                        'match' => $match['match'],
                        'severity' => $match['severity'],
                    ];
                }

                if ($food->allergens === null) {
                    $unverified[] = ['item_id' => $item->id, 'food_id' => $food->id, 'food_name_ar' => $food->name_ar ?? $food->name_en];
                }
            }
        }

        return ['conflicts' => $conflicts, 'unverified_items' => $unverified];
    }

    /**
     * One food against the patient's approved allergies, for the dashboard's
     * inline warning before an item is even added to the plan.
     *
     * @param  array{groups: array<string, string>, texts: array<string, string>}  $profile
     * @return array{groups: list<string>, match: string, severity: string}|null
     */
    public function matchFood(Food $food, array $profile): ?array
    {
        $groups = [];
        $severities = [];

        foreach (array_intersect($this->groupsOf($food), array_keys($profile['groups'])) as $group) {
            $groups[] = $group;
            $severities[] = $profile['groups'][$group];
        }

        $tagMatched = $groups !== [];
        $textMatched = false;

        if ($profile['texts'] !== []) {
            $names = array_filter([(string) $food->name_ar, (string) $food->name_en]);

            foreach ($profile['texts'] as $needle => $severity) {
                foreach ($names as $name) {
                    // Word by word, or as a plain substring as before: never fewer matches than the old rule.
                    if ($this->matcher->textInName((string) $needle, $name) || str_contains($this->fold($name), (string) $needle)) {
                        $textMatched = true;
                        $severities[] = $severity;
                        break;
                    }
                }
            }
        }

        if (! $tagMatched && ! $textMatched) {
            return null;
        }

        if ($textMatched) {
            $groups[] = 'other';
        }

        return [
            'groups' => array_values(array_unique($groups)),
            'match' => $tagMatched ? 'tag' : 'text',
            'severity' => $this->strongest($severities),
        ];
    }

    /**
     * The identity of a conflict for comparing against an acknowledgement:
     * the FOOD and its groups, not the item row, so moving a food between
     * meals or changing its portion does not make it "new", while swapping
     * in a different offending food does.
     *
     * @param  array<string, mixed>  $conflict
     */
    public function key(array $conflict): string
    {
        $groups = $conflict['groups'];
        sort($groups);

        return $conflict['food_id'].':'.implode(',', $groups);
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @return list<array<string, mixed>>
     */
    public function confirmed(array $conflicts): array
    {
        return array_values(array_filter($conflicts, fn (array $c) => $c['severity'] === self::CONFIRMED));
    }

    /**
     * An ACTIVE plan holding a confirmed-allergy conflict that its
     * acknowledgement does not cover: an edit introduced it, or an allergy
     * was approved after the plan was activated. A plan activated before
     * acknowledgements existed has none recorded, so every confirmed
     * conflict on it counts — the safe default.
     *
     * @param  list<array<string, mixed>>  $conflicts
     */
    public function hasNewUnacknowledged(MealPlan $plan, array $conflicts): bool
    {
        if ($plan->status !== 'active') {
            return false;
        }

        $acknowledged = array_map($this->key(...), $plan->allergen_ack_snapshot ?? []);

        foreach ($this->confirmed($conflicts) as $conflict) {
            if (! in_array($this->key($conflict), $acknowledged, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $classes */
    private function strongest(array $classes): string
    {
        $best = 'confirmed_allergy';
        $rank = 0;

        foreach ($classes as $class) {
            if ((self::SEVERITY_RANK[$class] ?? 0) > $rank) {
                $rank = self::SEVERITY_RANK[$class];
                $best = $class;
            }
        }

        // No row found for a matched group should be unreachable; the
        // safest reading of "an approved allergy of unknown class" is the strictest.
        return $best;
    }

    /** Spelling-insensitive, case-insensitive form for a substring match. */
    private function fold(string $text): string
    {
        return mb_strtolower((string) ArabicText::normalize($text));
    }
}
