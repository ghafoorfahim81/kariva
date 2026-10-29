<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** Afghan mobile numbers: any common way of typing one becomes E.164 (+937XXXXXXXX), or null. */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = Text::latinDigits($input);
        $digits = preg_replace('/[^\d+]/', '', $digits) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($digits, 'AF');
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumberForRegion($number, 'AF')) {
            return null;
        }

        $e164 = $util->format($number, PhoneNumberFormat::E164);

        return preg_match('/^\+937\d{8}$/', $e164) ? $e164 : null;
    }
}
