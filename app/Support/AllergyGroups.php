<?php

namespace App\Support;

/**
 * The nine allergen groups a patient's allergy and a food's tags share,
 * their Arabic labels, and the mapping from free text (the old JSON list,
 * a legacy client) to a group.
 */
class AllergyGroups
{
    public const LABELS_AR = [
        'tree_nuts' => 'المكسرات',
        'peanuts' => 'الفول السوداني',
        'milk_lactose' => 'الحليب ومشتقاته (اللاكتوز)',
        'egg' => 'البيض',
        'wheat_gluten' => 'القمح والجلوتين',
        'sesame' => 'السمسم',
        'fish' => 'السمك',
        'shellfish' => 'القشريات والمأكولات البحرية',
        'soy' => 'الصويا',
    ];

    /** Keywords (lower-case, Arabic normalized) per group, matched as substrings. */
    private const KEYWORDS = [
        'peanuts' => ['فول سوداني', 'فستق عبيد', 'peanut'],
        'tree_nuts' => ['مكسرات', 'لوز', 'جوز', 'كاجو', 'بندق', 'فستق', 'صنوبر', 'nut', 'almond', 'walnut', 'cashew', 'pistachio', 'hazelnut'],
        'milk_lactose' => ['لاكتوز', 'حليب', 'البان', 'الالبان', 'لبن', 'جبن', 'lactose', 'milk', 'dairy', 'cheese'],
        'egg' => ['بيض', 'egg'],
        'wheat_gluten' => ['جلوتين', 'غلوتين', 'قمح', 'سيلياك', 'gluten', 'wheat', 'celiac', 'coeliac'],
        'sesame' => ['سمسم', 'طحينه', 'طحينة', 'sesame', 'tahini'],
        'shellfish' => ['قشريات', 'روبيان', 'جمبري', 'قريدس', 'سلطعون', 'محار', 'shellfish', 'shrimp', 'crab', 'lobster'],
        'fish' => ['سمك', 'اسماك', 'fish'],
        'soy' => ['صويا', 'soy'],
    ];

    public static function fromText(string $text): string
    {
        $haystack = ArabicText::normalize(mb_strtolower(trim($text)));

        foreach (self::KEYWORDS as $group => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, ArabicText::normalize($word))) {
                    return $group;
                }
            }
        }

        return 'other';
    }

    public static function label(string $group, ?string $otherText = null): string
    {
        return $group === 'other' ? (string) $otherText : self::LABELS_AR[$group];
    }
}
