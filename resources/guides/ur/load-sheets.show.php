<?php

// Guide (Roman Urdu): Load Sheet. Labels match the screen exactly.

return [
    'title' => 'Load Sheet',
    'summary' => 'Kya load karna hai, product ba product, aur route ki tarteeb mein stops. Din ke aakhir mein settle karein.',
    'sections' => [
        [
            'id' => 'run',
            'heading' => 'Chakkar',
            'items' => [
                [
                    'label' => 'Print Load Sheet',
                    'text' => 'Gaari ke liye loading list aur stops, har customer ke baqi ke saath.',
                ],
                [
                    'label' => 'Deliver',
                    'text' => 'Order khol kar diya gaya maal aur wasooli darj karein.',
                ],
                [
                    'label' => 'Not delivered',
                    'text' => 'Order gaari se utar jata hai; booked rehta hai.',
                ],
                [
                    'label' => 'Settle',
                    'text' => 'Har order deliver ya wapas hone ke baad sheet band karta hai.',
                ],
            ],
        ],
    ],
];
