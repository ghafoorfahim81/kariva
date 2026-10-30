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

    'media' => [
        // Public files go to one disk, private ones (order files, verification documents) to another.
        'public_disk' => env('MEDIA_PUBLIC_DISK', 'public'),
        'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),
        // noop (development) or clamav (production: needs clamdscan on the server)
        'scanner' => env('MEDIA_SCANNER', 'noop'),

        // What each collection accepts. Size is checked on the real file, the type on its content, not on its name.
        'image_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'collections' => [
            'actor' => ['avatar' => ['max_kb' => 3072, 'max_count' => 1], 'cover' => ['max_kb' => 5120, 'max_count' => 1]],
            'service' => ['gallery' => ['max_kb' => 5120, 'max_count' => 8]],
            'portfolio_item' => ['images' => ['max_kb' => 5120, 'max_count' => 10]],
        ],
    ],
];
