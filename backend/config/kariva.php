<?php

return [
    // Times are stored in UTC and shown in this zone (Jalali calendar on the client).
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Kabul'),

    'currencies' => ['AFN', 'USD'],

    'otp' => [
        'digits' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'max_sends_per_hour' => 3,
        // The spec limits sends per phone and per IP. Many Afghan users share one mobile-carrier IP, so this is tunable.
        'max_sends_per_hour_per_ip' => (int) env('OTP_MAX_SENDS_PER_IP', 3),
        // Development only (ignored in production): a fixed code such as 1234, like the prototype.
        'fake_code' => env('OTP_FAKE_CODE'),
    ],

    // "Invited past job": confirmed by the customer via OTP, capped per provider per month (Asia/Kabul).
    'invited_orders_per_month' => 5,
];
