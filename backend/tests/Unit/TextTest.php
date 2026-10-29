<?php

namespace Tests\Unit;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase
{
    public function test_persian_and_arabic_digits_become_latin(): void
    {
        $this->assertSame('0123456789', Text::latinDigits('۰۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('0123456789', Text::latinDigits('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('abc 12', Text::latinDigits('abc ۱۲'));
    }

    public function test_arabic_letters_become_persian(): void
    {
        $this->assertSame('برق‌کار', Text::normalize('برق‌كار'));
        $this->assertSame('ایران', Text::normalize('ايران'));
        $this->assertSame('کتاب 1', Text::normalize('كتاب ١'));
    }

    public function test_search_form_is_lowercase_without_noise(): void
    {
        $this->assertSame('plumber kabul', Text::search('  Plumber,   KABUL! '));
        $this->assertSame('برقکار کابل', Text::search('برق‌کار  کابل'));
        $this->assertSame('موتر ساز', Text::search('مُوتر ساز'));
    }

    public function test_search_form_treats_arabic_and_persian_spelling_alike(): void
    {
        $this->assertSame(Text::search('پلمبر كابل ١٢'), Text::search('پلمبر کابل 12'));
    }

    public function test_search_form_of_empty_input_is_empty(): void
    {
        $this->assertSame('', Text::search("  \n "));
    }
}
