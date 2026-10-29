<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            'local with zero' => ['0791234567'],
            'local without zero' => ['791234567'],
            'e164' => ['+93791234567'],
            'double zero' => ['0093791234567'],
            'spaces and dashes' => ['+93 79-123 4567'],
            'persian digits' => ['۰۷۹۱۲۳۴۵۶۷'],
            'arabic digits' => ['٠٧٩١٢٣٤٥٦٧'],
            'persian digits e164' => ['+۹۳۷۹۱۲۳۴۵۶۷'],
            'dots and parens' => ['(079) 123.4567'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_common_ways_of_typing_a_number_become_e164(string $input): void
    {
        $this->assertSame('+93791234567', Phone::normalize($input));
    }

    public static function otherPrefixes(): array
    {
        return [['0701234567', '+93701234567'], ['0771234567', '+93771234567'], ['0721234567', '+93721234567']];
    }

    #[DataProvider('otherPrefixes')]
    public function test_other_afghan_mobile_prefixes_work(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty' => [''], 'null-like text' => ['abc'], 'too short' => ['07912345'], 'too long' => ['079123456789'],
            'foreign number' => ['+447911123456'], 'pakistan' => ['+923001234567'], 'landline-looking' => ['+9320123456'],
            'not starting with 7' => ['0691234567'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_anything_else_is_rejected(string $input): void
    {
        $this->assertNull(Phone::normalize($input));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(Phone::normalize(null));
    }

    public function test_the_result_always_matches_the_database_format(): void
    {
        foreach (['0791234567', '+93701234567', '۰۷۷۱۲۳۴۵۶۷'] as $input) {
            $this->assertMatchesRegularExpression('/^\+937[0-9]{8}$/', Phone::normalize($input));
        }
    }
}
