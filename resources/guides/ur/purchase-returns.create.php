<?php

// Guide (Roman Urdu): Return to Vendor. Labels match the screen exactly.

return [
    'title' => 'Return to Vendor',
    'summary' => 'Vendor chunein, phir uske har batch mein se kitna wapas ja raha hai. Har line usi batch se nikalti hai jis mein khareedi gayi thi, usi ki qeemat par.',
    'sections' => [
        [
            'id' => 'lines',
            'heading' => 'Kya wapas ja raha hai',
            'intro' => 'Sirf is vendor se khareede aur abhi stock mein mojood batches, expiry aur batch number ke saath.',
            'items' => [
                [
                    'label' => 'Vendor',
                    'text' => 'Jise maal wapas ja raha hai.',
                ],
                [
                    'label' => 'Find product',
                    'text' => 'Batches ki list chhoti karein.',
                ],
            ],
        ],
        [
            'id' => 'note',
            'heading' => 'Debit note',
            'items' => [
                [
                    'label' => 'Reason',
                    'text' => 'Expire, kharab, ghalat item, zaida ya koi aur wajah.',
                ],
                [
                    'label' => 'Save Return',
                    'text' => 'Stock kam hota hai aur raqam vendor ke baqi se kat jati hai. Is se zaida ho to vendor ke paas credit rehta hai.',
                ],
            ],
        ],
    ],
];
