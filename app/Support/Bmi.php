<?php

namespace App\Support;

/**
 * BMI arithmetic, in one place so the API, the dashboard suggestion and
 * the tests cannot each round it their own way.
 *
 * The value the nutritionist entered is always what gets stored and
 * shown (Item B) — this only produces the figure offered alongside it,
 * and the band its number falls in.
 */
final class Bmi
{
    /** WHO adult cut-offs. Below 18 they do not apply: see category(). */
    public const UNDERWEIGHT = 'underweight';

    public const NORMAL = 'normal';

    public const OVERWEIGHT = 'overweight';

    public const OBESITY = 'obesity';

    /** weight ÷ (height in metres)², to one decimal. Null when either figure is missing or zero. */
    public static function compute(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $weightKg <= 0 || $heightCm <= 0) {
            return null;
        }

        $metres = $heightCm / 100;

        return round($weightKg / ($metres ** 2), 1);
    }

    /**
     * The WHO band, for adults only.
     *
     * Under 18 a BMI is read against age-and-sex percentile charts, not
     * these fixed cut-offs, so this returns null rather than labelling a
     * 14-year-old "obese" off an adult table. The number itself is still
     * shown; only the label is withheld.
     */
    public static function category(?float $bmi, ?int $age): ?string
    {
        if ($bmi === null || $age === null || $age < 18) {
            return null;
        }

        return match (true) {
            $bmi < 18.5 => self::UNDERWEIGHT,
            $bmi < 25.0 => self::NORMAL,
            $bmi < 30.0 => self::OVERWEIGHT,
            default => self::OBESITY,
        };
    }
}
