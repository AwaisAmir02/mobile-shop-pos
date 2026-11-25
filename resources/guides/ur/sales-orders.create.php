<?php

// Guide (Roman Urdu): Book Order. Labels match the screen exactly.

return [
    'title' => 'Book Order',
    'summary' => 'Customer ko jo deliver karna hai woh book karein. Qeemat unki price level se shuru hoti hai aur badli ja sakti hai; stock mein jitna khali hai us se zaida book nahin hota.',
    'sections' => [
        [
            'id' => 'items',
            'heading' => 'Items',
            'intro' => 'Har line batati hai kitna book ho sakta hai. Chalti scheme delivery par lagti hai.',
            'items' => [
                [
                    'label' => 'Add a product',
                    'text' => 'Dhoond kar order mein daalein.',
                ],
                [
                    'label' => 'Qty',
                    'text' => 'Pieces mein, ya cartons mein jab line yeh bataye.',
                ],
                [
                    'label' => 'Price each',
                    'text' => 'Customer ki qeemat se shuru; tay ho to badlein.',
                ],
            ],
        ],
        [
            'id' => 'order',
            'heading' => 'Order',
            'items' => [
                [
                    'label' => 'Booked by',
                    'text' => 'Woh booker ya salesman jis ne order liya.',
                ],
                [
                    'label' => 'Book Order',
                    'text' => 'Save karta hai aur stock rok leta hai. Credit limit se oopar yahan sirf khabardar karta hai.',
                ],
            ],
        ],
    ],
];
