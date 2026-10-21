<?php

namespace App\Support;

/**
 * The structured medical conditions a nutritionist records, their Arabic
 * labels (one place, like AllergyGroups) and the mapping from the old free
 * text to a code.
 */
class ConditionCodes
{
    public const LABELS_AR = [
        'diabetes_type1' => 'سكري النوع الأول',
        'diabetes_type2' => 'سكري النوع الثاني',
        'gestational_diabetes' => 'سكري الحمل',
        'insulin_resistance' => 'مقاومة الإنسولين',
        'prediabetes' => 'ما قبل السكري',
        'hypertension' => 'ارتفاع ضغط الدم',
        'kidney_disease' => 'أمراض الكلى',
        'liver_disease' => 'أمراض الكبد',
        'cancer' => 'السرطان',
        'other' => 'أخرى',
    ];

    public const CODES = [
        'diabetes_type1', 'diabetes_type2', 'gestational_diabetes', 'insulin_resistance',
        'prediabetes', 'hypertension', 'kidney_disease', 'liver_disease', 'cancer', 'other',
    ];

    /** Every code that means "has diabetes" for the glycemic warnings. */
    public const DIABETES = ['diabetes_type1', 'diabetes_type2', 'gestational_diabetes'];

    /**
     * Keywords (Arabic-normalized substrings) per code, most specific first.
     * Anything unmatched maps to "other" with the original text kept.
     */
    private const KEYWORDS = [
        'gestational_diabetes' => ['سكري الحمل', 'سكر الحمل', 'gestational'],
        'diabetes_type1' => ['النوع الاول', 'نوع اول', 'نوع 1', 'type 1', 'type1', 'سكري الاطفال'],
        'diabetes_type2' => ['النوع الثاني', 'نوع ثاني', 'نوع 2', 'type 2', 'type2'],
        'prediabetes' => ['ما قبل السكري', 'قبل السكري', 'prediabet'],
        'insulin_resistance' => ['مقاومة الانسولين', 'مقاومه الانسولين', 'insulin resistance'],
        'hypertension' => ['ضغط', 'hypertension', 'blood pressure'],
        'kidney_disease' => ['كلى', 'كلية', 'كلوي', 'kidney', 'renal'],
        'liver_disease' => ['كبد', 'liver', 'hepat'],
        'cancer' => ['سرطان', 'ورم', 'cancer', 'tumor', 'tumour'],
        // A bare "سكري" (no type said) is left to the nutritionist: type 2
        // is the common case but it is not this code's call to make.
    ];

    public static function label(string $code, ?string $otherText = null): string
    {
        return $code === 'other' ? (string) $otherText : self::LABELS_AR[$code];
    }

    /** @return array{code: string, other_text: ?string} */
    public static function fromText(string $text): array
    {
        $haystack = ArabicText::normalize(mb_strtolower(trim($text)));

        foreach (self::KEYWORDS as $code => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, ArabicText::normalize(mb_strtolower($word)))) {
                    return ['code' => $code, 'other_text' => null];
                }
            }
        }

        return ['code' => 'other', 'other_text' => mb_substr(trim($text), 0, 100)];
    }
}
