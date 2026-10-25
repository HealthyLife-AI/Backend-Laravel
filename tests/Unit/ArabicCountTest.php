<?php

namespace Tests\Unit;

use App\Support\ArabicCount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Arabic number agreement: 1 and 2 are words, 3-10 take the plural, 11+ the singular accusative. */
class ArabicCountTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function days(): array
    {
        return [
            'one' => [1, 'يوم واحد'],
            'two' => [2, 'يومين'],
            'three' => [3, '3 أيام'],
            'six' => [6, '6 أيام'],
            'ten, the last plural' => [10, '10 أيام'],
            'eleven, the first singular' => [11, '11 يومًا'],
            'fourteen' => [14, '14 يومًا'],
            'twenty eight' => [28, '28 يومًا'],
            'a hundred' => [100, '100 يومًا'],
            'zero' => [0, '0 يومًا'],
        ];
    }

    #[DataProvider('days')]
    public function test_days(int $count, string $expected): void
    {
        $this->assertSame($expected, ArabicCount::days($count));
    }

    /** @return array<string, array{int, string}> */
    public static function weeks(): array
    {
        return [
            'one' => [1, 'أسبوع واحد'],
            'two' => [2, 'أسبوعين'],
            'three' => [3, '3 أسابيع'],
            'ten' => [10, '10 أسابيع'],
            'eleven' => [11, '11 أسبوعًا'],
            'twenty' => [20, '20 أسبوعًا'],
        ];
    }

    #[DataProvider('weeks')]
    public function test_weeks(int $count, string $expected): void
    {
        $this->assertSame($expected, ArabicCount::weeks($count));
    }

    /** @return array<string, array{int, string}> */
    public static function newTips(): array
    {
        return [
            'one' => [1, 'نصيحة جديدة'],
            'two' => [2, 'نصيحتان جديدتان'],
            'five' => [5, '5 نصائح جديدة'],
            'twelve' => [12, '12 نصيحة جديدة'],
        ];
    }

    #[DataProvider('newTips')]
    public function test_new_tips(int $count, string $expected): void
    {
        $this->assertSame($expected, ArabicCount::newTips($count));
    }

    public function test_the_boundaries_are_exact(): void
    {
        $this->assertStringContainsString('أيام', ArabicCount::days(10));
        $this->assertStringContainsString('يومًا', ArabicCount::days(11));
        $this->assertStringNotContainsString('أيام', ArabicCount::days(11));
    }

    public function test_a_negative_count_reads_as_its_size(): void
    {
        $this->assertSame('3 أيام', ArabicCount::days(-3));
    }

    public function test_any_counted_noun_can_use_it(): void
    {
        $meals = ['one' => 'وجبة واحدة', 'two' => 'وجبتين', 'few' => ':n وجبات', 'many' => ':n وجبة'];

        $this->assertSame('وجبة واحدة', ArabicCount::format(1, $meals));
        $this->assertSame('وجبتين', ArabicCount::format(2, $meals));
        $this->assertSame('7 وجبات', ArabicCount::format(7, $meals));
        $this->assertSame('15 وجبة', ArabicCount::format(15, $meals));
    }
}
