<?php

// Guide (Roman Urdu): Sales by Booker & Route. Labels match the screen exactly.

return [
    'title' => 'Sales by Booker & Route',
    'summary' => 'Kisi bhi tareekhon ke liye booker, route aur brand ke hisaab se sales, laagat aur gross profit.',
    'sections' => [
        [
            'id' => 'report',
            'heading' => 'Report',
            'intro' => 'Ek view chunein aur filters se chhota karein, jaise ek booker ke brands.',
            'items' => [
                [
                    'label' => 'By',
                    'text' => 'Booker, Route ya Brand.',
                ],
                [
                    'label' => 'Booker',
                    'text' => 'Sirf ek shakhs ki book ki hui sales.',
                ],
                [
                    'label' => 'Route',
                    'text' => 'Sirf ek route ke customers.',
                ],
            ],
        ],
    ],
];
