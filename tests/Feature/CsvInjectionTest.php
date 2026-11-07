<?php

namespace Tests\Feature;

use App\Support\CsvSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CsvInjectionTest extends TestCase
{
    public static function formulas(): array
    {
        return array_map(fn (string $text): array => [$text], ["=cmd|' /C calc'!A0", '+SUM(1)', '-2+3', '@x', "\t=1", "\r=1", ' =1', "\n=1", '  +1', "\x00=1", '==x']);
    }

    #[DataProvider('formulas')]
    public function test_a_text_that_could_run_as_a_formula_is_neutralised(string $text): void
    {
        $this->assertSame("'".$text, CsvSafe::cell($text));
    }

    public function test_plain_text_and_numbers_are_left_alone(): void
    {
        foreach (['card', 'a=b', 'x-y', 'e-mail', '12', 'é', ''] as $text) {
            $this->assertSame($text, CsvSafe::cell($text));
        }
        $this->assertSame(-3, CsvSafe::cell(-3));
        $this->assertSame(2.5, CsvSafe::cell(2.5));
        $this->assertNull(CsvSafe::cell(null));
        $this->assertTrue(CsvSafe::cell(true));
    }

    public function test_text_that_is_not_valid_utf8_is_still_checked(): void
    {
        $this->assertSame("'=\xff", CsvSafe::cell("=\xff"));
    }

    public function test_a_row_keeps_its_order(): void
    {
        $this->assertSame(['a', "'=b", 3, null], CsvSafe::row(['x' => 'a', 'y' => '=b', 'z' => 3, 'w' => null]));
    }
}
