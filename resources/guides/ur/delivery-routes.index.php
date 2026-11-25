<?php

// Guide (Roman Urdu): Routes. Labels match the screen exactly.

return [
    'title' => 'Routes',
    'summary' => 'Aap ke bookers ke beats: har route par customers usi tarteeb mein jis mein unke paas jaya jata hai. Load sheet stops isi tarteeb se dikhati hai.',
    'sections' => [
        [
            'id' => 'routes',
            'heading' => 'Routes',
            'items' => [
                [
                    'label' => 'New Route',
                    'text' => 'Naam, jane ke din aur woh booker jo isay cover karta hai.',
                ],
                [
                    'label' => 'Add to route',
                    'text' => 'Customer ko route ke aakhir mein shamil karein.',
                ],
                [
                    'label' => 'Remove',
                    'text' => 'Customer ko route se hatayein.',
                ],
            ],
        ],
        [
            'id' => 'order',
            'heading' => 'Jane ki tarteeb',
            'intro' => 'Teer ke nishan se stop ko pehle ya baad mein karein.',
        ],
    ],
];
