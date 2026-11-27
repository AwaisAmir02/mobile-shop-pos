<?php

// Guide (Roman Urdu): Staff Attendance. Labels match the screen exactly.

return [
    'title' => 'Staff Attendance',
    'summary' => 'Kaun kab aaya. Staff apne fingerprint, QR badge, ya yahan tap se aate jaate waqt darj karta hai; din ka pehla waqt aamad aur aakhri rawangi hai.',
    'sections' => [
        [
            'id' => 'clock',
            'heading' => 'Aamad aur rawangi',
            'intro' => 'Teen tareeqe, sab isi list mein.',
            'items' => [
                [
                    'label' => 'Clock in/out',
                    'text' => 'Us shakhs ka abhi ka waqt darj karta hai.',
                ],
                [
                    'label' => 'Scan a badge',
                    'text' => 'Staff badge ka QR scanner se parhein. Badge se print karein.',
                ],
                [
                    'label' => 'To device',
                    'text' => 'Fingerprint device par us shakhs ko number deta hai. Device par usi number se ungli darj karein.',
                ],
            ],
        ],
        [
            'id' => 'payroll',
            'heading' => 'Payroll ke saath',
            'intro' => 'Mahine mein haazri ke din: jis din kam az kam ek dafa clock hua.',
            'items' => [
                [
                    'label' => 'Days present in',
                    'text' => 'Us mahine ki daily-wage payslip inhi dinon se shuru hoti hai. Payslip par aap badal sakte hain.',
                ],
            ],
        ],
    ],
];
