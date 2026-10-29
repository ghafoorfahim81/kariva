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
    ],

    // "Invited past job": confirmed by the customer via OTP, capped per provider per month (Asia/Kabul).
    'invited_orders_per_month' => 5,
];
