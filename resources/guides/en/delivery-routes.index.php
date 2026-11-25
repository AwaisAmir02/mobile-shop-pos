<?php

// Guide: Routes. Labels match the screen exactly.

return [
    'title' => 'Routes',
    'summary' => 'Your bookers\' beats: the customers on each route in the order they are visited. Load sheets list stops in this order.',
    'sections' => [
        [
            'id' => 'routes',
            'heading' => 'Routes',
            'items' => [
                [
                    'label' => 'New Route',
                    'text' => 'A name, the visiting days and the booker who covers it.',
                ],
                [
                    'label' => 'Add to route',
                    'text' => 'Put a customer on the route, at the end.',
                ],
                [
                    'label' => 'Remove',
                    'text' => 'Take a customer off the route.',
                ],
            ],
        ],
        [
            'id' => 'order',
            'heading' => 'Visiting order',
            'intro' => 'Use the arrows to move a stop earlier or later.',
        ],
    ],
];
