<?php

namespace App\Services\MealPlans;

use App\Models\Food;
use App\Models\MealPlan;
use App\Models\PatientAllergy;
use App\Models\Subscriber;
use App\Services\HealthRecords\HealthRecordService;
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
 * Two kinds of match:
 *  - "tag": the food's own `allergens` list intersects an approved group.
 *  - "text": an approved "other" allergy's free text is found in the food's
 *    Arabic (spelling-normalised) or English name. A name match is a hint,
 *    not a proof, which is why `match` travels with every conflict.
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

    public function __construct(private readonly HealthRecordService $records) {}

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

        return ['groups' => $groups, 'texts' => $texts];
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

        foreach (array_intersect($food->allergens ?? [], array_keys($profile['groups'])) as $group) {
            $groups[] = $group;
            $severities[] = $profile['groups'][$group];
        }

        $tagMatched = $groups !== [];
        $textMatched = false;

        if ($profile['texts'] !== []) {
            $haystacks = [$this->fold((string) $food->name_ar), $this->fold((string) $food->name_en)];

            foreach ($profile['texts'] as $needle => $severity) {
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, (string) $needle)) {
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
