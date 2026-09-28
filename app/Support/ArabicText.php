<?php

namespace App\Support;

/**
 * Spelling-insensitive form of Arabic text for search. People type "ارز"
 * for "أرز" and "بيضه" for "بيضة", so both the stored name and the query
 * are reduced to one form before they're compared:
 *
 * - أ إ آ ٱ → ا
 * - ة → ه
 * - ى → ي
 * - tatweel (ـ) and harakat/diacritics removed
 * - whitespace runs collapsed
 *
 * Non-Arabic characters pass through unchanged.
 */
final class ArabicText
{
    public static function normalize(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
