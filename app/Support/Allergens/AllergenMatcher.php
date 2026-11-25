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

    /**
     * Keyword and exception phrases indexed by their first word, so a name is
     * checked against only the phrases that can start at each of its words.
     *
     * @var array{keywords: array<string, list<array{string, list<string>}>>, not: array<string, list<array{string, list<string>}>>}|null
     */
    private ?array $index = null;

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

        $index = $this->index();

        // Words covered by an exception phrase ("peanut butter") can't count
        // for that phrase's group, but stay available to every other group.
        $blocked = [];
        foreach ($this->hits($words, $index['not']) as [$group, $start, $length]) {
            for ($i = $start; $i < $start + $length; $i++) {
                $blocked[$group][$i] = true;
            }
        }

        $found = [];
        foreach ($this->hits($words, $index['keywords']) as [$group, $start]) {
            if (! isset($blocked[$group][$start])) {
                $found[$group] = true;
            }
        }

        $found = array_keys($found);
        sort($found);

        return $found;
    }

    /**
     * Whether a free-text allergy the nutritionist typed ("فول سوداني",
     * "peanuts") appears in a food name: its words, in order, each matching
     * a form of a name word. Both sides fold the Arabic article and prefixes
     * and English plurals, so «فول سوداني» is found in «زبدة الفول السوداني»
     * and "peanuts" in "Peanut butter".
     */
    public function textInName(string $allergyText, ?string $name): bool
    {
        $needle = $this->words($allergyText);
        $haystack = $this->words((string) $name);
        $length = count($needle);

        for ($start = 0; $length > 0 && $start + $length <= count($haystack); $start++) {
            for ($k = 0; $k < $length; $k++) {
                if (array_intersect($needle[$k], $haystack[$start + $k]) === []) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Every indexed phrase found in the name: [group, start, length].
     *
     * @param  list<list<string>>  $words
     * @param  array<string, list<array{string, list<string>}>>  $byFirstWord
     * @return list<array{string, int, int}>
     */
    private function hits(array $words, array $byFirstWord): array
    {
        $hits = [];

        foreach ($words as $start => $forms) {
            foreach ($forms as $form) {
                foreach ($byFirstWord[$form] ?? [] as [$group, $phrase]) {
                    if ($this->phraseAt($words, $start, $phrase)) {
                        $hits[] = [$group, $start, count($phrase)];
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * @param  list<list<string>>  $words
     * @param  list<string>  $phrase
     */
    private function phraseAt(array $words, int $start, array $phrase): bool
    {
        foreach ($phrase as $k => $word) {
            if (! isset($words[$start + $k]) || ! in_array($word, $words[$start + $k], true)) {
                return false;
            }
        }

        return true;
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

    /** @return array{keywords: array<string, list<array{string, list<string>}>>, not: array<string, list<array{string, list<string>}>>} */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = ['keywords' => [], 'not' => []];

        foreach (['keywords' => 'allergens.keywords', 'not' => 'allergens.not'] as $kind => $key) {
            foreach ((array) config($key) as $group => $byLanguage) {
                foreach (array_merge(...array_values((array) $byLanguage)) as $keyword) {
                    $phrase = $this->phrase($keyword);
                    if ($phrase !== []) {
                        $this->index[$kind][$phrase[0]][] = [$group, $phrase];
                    }
                }
            }
        }

        return $this->index;
    }
}
