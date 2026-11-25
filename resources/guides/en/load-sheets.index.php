<?php

// Guide: Load Sheets. Labels match the screen exactly.

return [
    'title' => 'Load Sheets',
    'summary' => 'A load sheet is one van run: the booked orders going out together.',
    'sections' => [
        [
            'id' => 'load',
            'heading' => 'Load a van',
            'intro' => 'Choose a route to pick its booked orders, then the date, vehicle, driver and salesman.',
            'items' => [
                [
                    'label' => 'Create Load Sheet',
                    'text' => 'Puts the ticked orders on the van.',
                ],
            ],
        ],
    ],
];
