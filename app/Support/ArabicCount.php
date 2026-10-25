<?php

namespace App\Support;

/**
 * A counted noun in correct Arabic agreement, for the texts the server writes
 * itself (fallback explanations, the morning reminders):
 *
 * - 1      the singular, with "one" where Arabic says it ("يوم واحد")
 * - 2      the dual ("يومين")
 * - 3-10   the number and the plural ("5 أيام")
 * - 11+    the number and the singular in the accusative ("12 يومًا")
 *
 * Digits stay Latin, like everywhere else in the app. Zero reads as the large
 * numbers do ("0 يومًا").
 */
final class ArabicCount
{
    /**
     * @param  array{one: string, two: string, few: string, many: string}  $forms  `few` and `many` take the number as `:n`
     */
    public static function format(int $count, array $forms): string
    {
        $count = abs($count);

        return match (true) {
            $count === 1 => $forms['one'],
            $count === 2 => $forms['two'],
            $count >= 3 && $count <= 10 => str_replace(':n', (string) $count, $forms['few']),
            default => str_replace(':n', (string) $count, $forms['many']),
        };
    }

    public static function days(int $count): string
    {
        return self::format($count, ['one' => 'يوم واحد', 'two' => 'يومين', 'few' => ':n أيام', 'many' => ':n يومًا']);
    }

    public static function weeks(int $count): string
    {
        return self::format($count, ['one' => 'أسبوع واحد', 'two' => 'أسبوعين', 'few' => ':n أسابيع', 'many' => ':n أسبوعًا']);
    }

    /** "عندك ..." + this: the count of new tips. */
    public static function newTips(int $count): string
    {
        return self::format($count, ['one' => 'نصيحة جديدة', 'two' => 'نصيحتان جديدتان', 'few' => ':n نصائح جديدة', 'many' => ':n نصيحة جديدة']);
    }
}
