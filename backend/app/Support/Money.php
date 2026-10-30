<?php

namespace App\Support;

/** Amounts typed by people: Persian or Arabic digits, thousands separators. */
final class Money
{
    /** "۱٬۲۵۰" and "1,250" both become "1250". Returns the input unchanged when it is not a string. */
    public static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return str_replace([',', '٬', ' '], '', Text::latinDigits($value));
    }
}
