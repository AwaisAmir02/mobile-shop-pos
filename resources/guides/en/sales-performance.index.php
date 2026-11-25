<?php

// Guide: Sales by Booker & Route. Labels match the screen exactly.

return [
    'title' => 'Sales by Booker & Route',
    'summary' => 'Sales, cost and gross profit by booker, by route and by brand, for any dates.',
    'sections' => [
        [
            'id' => 'report',
            'heading' => 'The report',
            'intro' => 'Pick one view and narrow it with the filters, e.g. one booker\'s brands.',
            'items' => [
                [
                    'label' => 'By',
                    'text' => 'Booker, Route or Brand.',
                ],
                [
                    'label' => 'Booker',
                    'text' => 'Only sales booked by one person.',
                ],
                [
                    'label' => 'Route',
                    'text' => 'Only customers on one route.',
                ],
            ],
        ],
    ],
];
