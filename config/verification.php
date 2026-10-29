<?php

return [
    'code_ttl_minutes' => 10,
    'max_attempts' => 5,
    'resend_cooldown_seconds' => 60,
    'max_sends_per_destination_per_hour' => 5,
    'max_sends_per_ip_per_hour' => 15,

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_OTP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_OTP_ACCESS_TOKEN'),
        'template' => env('WHATSAPP_OTP_TEMPLATE', 'hok_login_code'),
        'language' => env('WHATSAPP_OTP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'plivo' => [
        'auth_id' => env('PLIVO_AUTH_ID'),
        'auth_token' => env('PLIVO_AUTH_TOKEN'),
        'sender' => env('PLIVO_SENDER'),
    ],
];
