<?php

return [
    'gateway' => env('BILLING_GATEWAY', 'manual_bank_transfer'),

    'manual_bank_transfer' => [
        'instructions' => env('BILLING_BANK_INSTRUCTIONS', "PLACEHOLDER BANK DETAILS — replace before launch\nBank: Example Bank Limited\nAccount title: HisabOKitab (placeholder)\nAccount number: 0000-0000000000\nIBAN: PK00 XXXX 0000 0000 0000 0000"),
    ],

    'proof_max_kb' => 4096,

    'signup' => [
        'min_seconds' => 3,
        'per_hour' => 3,
        'per_day' => 10,
        'attempts_per_10_minutes' => 10,
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],
];
