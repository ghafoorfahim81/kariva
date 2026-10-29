<?php

namespace App\Support;

/** Input normalization shared by registration, search and search_text. */
final class Text
{
    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Persian and Arabic digits to Latin digits. */
    public static function latinDigits(string $value): string
    {
        return strtr($value, self::DIGITS);
    }

    /** Arabic "ي" and "ك" to Persian "ی" and "ک"; digits to Latin. */
    public static function normalize(string $value): string
    {
        $value = self::latinDigits($value);

        return strtr($value, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ە' => 'ه']);
    }

    /** Lowercase, normalized, no diacritics or half-spaces, single spaces: the form stored in search_text. */
    public static function search(string $value): string
    {
        $value = self::normalize($value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{200C}\x{200D}\x{0640}]/u', '', $value) ?? $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
