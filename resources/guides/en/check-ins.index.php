<?php

// Guide: Check-ins. Labels match the screen exactly.

return [
    'title' => 'Check-ins',
    'summary' => 'Members coming in today, at the desk or by fingerprint, with their membership and what they owe.',
    'sections' => [
        [
            'id' => 'desk',
            'heading' => 'At the desk',
            'intro' => 'Find the member, look, then check them in.',
            'items' => [
                [
                    'label' => 'Scan a member card or staff badge',
                    'text' => 'A member card or staff badge QR: a scanner types it here. Print a member card with Card.',
                ],
                [
                    'label' => 'Owes',
                    'text' => 'Unpaid bills and udhaar, as on the Udhaar list.',
                ],
            ],
        ],
    ],
];
