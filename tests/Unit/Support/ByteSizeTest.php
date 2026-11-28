<?php

namespace Tests\Unit\Support;

use App\Support\ByteSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * db:backup prints sizes with ByteSize, which must not depend on ext-intl
 * (audit F-OPS-01). Plain PHPUnit TestCase: no app, no intl, no container.
 */
class ByteSizeTest extends TestCase
{
    /** @return array<string, array{int|float, string}> */
    public static function sizes(): array
    {
        return [
            'bytes' => [512, '512 B'],
            'just under the KB step' => [921, '921 B'],
            'kilobytes' => [258 * 1024, '258 KB'],
            'telegram cap' => [49 * 1024 * 1024, '49 MB'],
            'gigabytes' => [3 * 1024 ** 3, '3 GB'],
            'zero' => [0, '0 B'],
        ];
    }

    #[DataProvider('sizes')]
    public function test_it_formats_like_number_file_size_without_intl(int|float $bytes, string $expected): void
    {
        $this->assertSame($expected, ByteSize::format($bytes));
    }

    public function test_the_backup_command_no_longer_calls_the_intl_formatter(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Console/Commands/Backup/BackupDatabase.php');

        $this->assertStringNotContainsString('Number::', $source);
    }
}
