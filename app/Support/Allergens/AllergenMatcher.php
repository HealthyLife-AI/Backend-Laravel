<?php

namespace App\Support\Allergens;

use App\Support\ArabicText;

/**
 * Finds allergen groups in a food name, from the one keyword list in
 * config/allergens.php (Arabic and English). Used for tagging foods
 * (FoodTagger, `foods:tag-allergens`) and, through AllergenConflictService,
 * by every allergen check.
 *
 * Names are compared word by word, never as raw substrings: "بيض" (egg)
 * must not match "أبيض" (white), "nut" must not match "nutmeg". Each word
 * of the name is also tried without an attached Arabic prefix ("الجبنة",
 * "بالجبنة" → "جبنه") and without an English plural ending ("peanuts" →
 * "peanut"). Leans towards matching: see the rule in config/allergens.php.
 */
final class AllergenMatcher
{
    /**
     * Attached Arabic prefixes, longest first (after ArabicText::normalize). ك، ف and a
     * lone ل are left out on purpose: they rarely attach to a food name and only make
     * false matches ("كفتة" would read as "فتة").
     */
    private const AR_PREFIXES = ['وبال', 'وال', 'بال', 'لل', 'ال', 'و', 'ب'];

    /** @var array<string, array{keywords: list<list<string>>, not: list<list<string>>}>|null */
    private ?array $groups = null;

    /**
     * Allergen groups whose keywords appear in the name, sorted.
     *
     * @return list<string>
     */
    public function groupsInName(?string $name): array
    {
        $words = $this->words((string) $name);

        if ($words === []) {
            return [];
        }

        $found = [];

        foreach ($this->groups() as $group => $lists) {
            // Words covered by an exception phrase ("peanut butter") can't
            // count for this group, but stay available to every other group.
            $blocked = [];
            foreach ($lists['not'] as $phrase) {
                foreach ($this->phrasePositions($words, $phrase) as $start) {
                    for ($i = $start; $i < $start + count($phrase); $i++) {
                        $blocked[$i] = true;
                    }
                }
            }

            foreach ($lists['keywords'] as $phrase) {
                foreach ($this->phrasePositions($words, $phrase) as $start) {
                    if (! isset($blocked[$start])) {
                        $found[] = $group;

                        continue 3;
                    }
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The name split into words, each with the forms it may stand for.
     *
     * @return list<list<string>>
     */
    public function words(string $text): array
    {
        $text = mb_strtolower((string) ArabicText::normalize($text));
        $words = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $words[] = $this->formsOf($word);
        }

        return $words;
    }

    /**
     * One keyword (possibly a phrase) as the base form of each of its words.
     *
     * @return list<string>
     */
    public function phrase(string $keyword): array
    {
        $text = mb_strtolower((string) ArabicText::normalize($keyword));

        // "الفول" in a keyword is compared as "فول": the name side offers both.
        return array_map(
            fn (string $word) => str_starts_with($word, 'ال') && mb_strlen($word) >= 4 ? mb_substr($word, 2) : $word,
            preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY),
        );
    }

    /**
     * Where a phrase starts in the name: each phrase word must be one of the
     * forms of the name word at that position.
     *
     * @param  list<list<string>>  $words
     * @param  list<string>  $phrase
     * @return list<int>
     */
    public function phrasePositions(array $words, array $phrase): array
    {
        $starts = [];
        $length = count($phrase);

        for ($start = 0; $length > 0 && $start + $length <= count($words); $start++) {
            for ($k = 0; $k < $length; $k++) {
                if (! in_array($phrase[$k], $words[$start + $k], true)) {
                    continue 2;
                }
            }
            $starts[] = $start;
        }

        return $starts;
    }

    /** @return list<string> */
    private function formsOf(string $word): array
    {
        $forms = [$word];

        if (preg_match('/\p{Arabic}/u', $word)) {
            foreach (self::AR_PREFIXES as $prefix) {
                if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= 2) {
                    $forms[] = mb_substr($word, mb_strlen($prefix));
                }
            }

            return array_values(array_unique($forms));
        }

        if (mb_strlen($word) > 3) {
            if (str_ends_with($word, 'ies')) {
                $forms[] = mb_substr($word, 0, -3).'y';
            } elseif (str_ends_with($word, 'es')) {
                $forms[] = mb_substr($word, 0, -2);
                $forms[] = mb_substr($word, 0, -1);
            } elseif (str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $forms[] = mb_substr($word, 0, -1);
            }
        }

        return array_values(array_unique($forms));
    }

    /** @return array<string, array{keywords: list<list<string>>, not: list<list<string>>}> */
    private function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $this->groups = [];

        foreach ((array) config('allergens.keywords') as $group => $byLanguage) {
            $this->groups[$group] = [
                'keywords' => $this->phrases(array_merge(...array_values((array) $byLanguage))),
                'not' => $this->phrases(array_merge(...array_values((array) config("allergens.not.{$group}", [])))),
            ];
        }

        return $this->groups;
    }

    /**
     * @param  list<string>  $keywords
     * @return list<list<string>>
     */
    private function phrases(array $keywords): array
    {
        return array_values(array_filter(array_map($this->phrase(...), $keywords)));
    }
}
