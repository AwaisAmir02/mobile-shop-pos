<?php

// Guide (Roman Urdu): Order. Labels match the screen exactly.

return [
    'title' => 'Order',
    'summary' => 'Ek order. Delivery par yeh us maal ki sale ban jata hai jo waqai diya gaya.',
    'sections' => [
        [
            'id' => 'deliver',
            'heading' => 'Delivery',
            'items' => [
                [
                    'label' => 'Delivered',
                    'text' => 'Jo maal diya aur jo paise mile darj karein. Baqi unki payment terms ke saath udhaar par.',
                ],
                [
                    'label' => 'Delivery challan',
                    'text' => 'Maal ke saath jane wala challan, customer ke dastakhat ke liye.',
                ],
                [
                    'label' => 'Not delivered',
                    'text' => 'Gaari se utar deta hai; order booked rehta hai.',
                ],
                [
                    'label' => 'Cancel order',
                    'text' => 'Iska stock dobara khali ho jata hai.',
                ],
            ],
        ],
    ],
];
