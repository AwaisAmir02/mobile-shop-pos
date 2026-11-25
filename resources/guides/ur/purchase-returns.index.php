<?php

// Guide (Roman Urdu): Purchase Returns. Labels match the screen exactly.

return [
    'title' => 'Purchase Returns',
    'summary' => 'Vendors ko wapas bheja gaya maal, har ek ke saath debit note. Maal apni khareed ki qeemat par stock se nikalta hai aur utni raqam vendor ke baqi se kam ho jati hai.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                [
                    'label' => 'Return to Vendor',
                    'text' => 'Naya wapas jane wala maal darj karein.',
                ],
                [
                    'label' => 'Debit note PDF',
                    'text' => 'Maal ke saath bhejne wala note, item ba item.',
                ],
                [
                    'label' => 'Cancel',
                    'text' => 'Agar vendor ne maal nahin liya: maal wapas stock mein aur dobara baqi.',
                ],
            ],
        ],
    ],
];
