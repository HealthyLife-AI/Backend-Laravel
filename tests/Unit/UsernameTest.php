<?php

namespace Tests\Unit;

use App\Rules\PatientUsername;
use App\Support\Username;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    public function test_normalises_case_spaces_and_eastern_digits(): void
    {
        $this->assertSame('sara.k', Username::normalize('  Sara.K '));
        $this->assertSame('ali2026', Username::normalize('ALI٢٠٢٦'));
        $this->assertSame('ali2026', Username::normalize('ali۲۰۲۶'));
        $this->assertNull(Username::normalize(null));
    }

    /** @return array<string, array{string, bool}> */
    public static function names(): array
    {
        return [
            'letters and dot' => ['sara.k', true],
            'digits start' => ['2sara', true],
            'underscore and hyphen' => ['abu_ali-1', true],
            'three chars' => ['abc', true],
            'thirty chars' => [str_repeat('a', 30), true],
            'too short' => ['ab', false],
            'too long' => [str_repeat('a', 31), false],
            'starts with dot' => ['.sara', false],
            'space inside' => ['sara k', false],
            'at sign' => ['sara@x', false],
            'arabic letters' => ['سارة', false],
        ];
    }

    #[DataProvider('names')]
    public function test_pattern(string $name, bool $valid): void
    {
        $this->assertSame($valid, Validator::make(['u' => Username::normalize($name)], ['u' => [new PatientUsername]])->passes());
    }

    public function test_arabic_letters_get_their_own_message(): void
    {
        $errors = Validator::make(['u' => 'سارة123'], ['u' => [new PatientUsername]])->errors()->first('u');

        $this->assertStringContainsString('no Arabic letters', $errors);
    }
}
