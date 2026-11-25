<?php

// Guide: Load Sheet. Labels match the screen exactly.

return [
    'title' => 'Load Sheet',
    'summary' => 'What to load, product by product, and the stops in route order. Settle it at the end of the day.',
    'sections' => [
        [
            'id' => 'run',
            'heading' => 'The run',
            'items' => [
                [
                    'label' => 'Print Load Sheet',
                    'text' => 'The loading list and the stops, with what each customer owes, for the van.',
                ],
                [
                    'label' => 'Deliver',
                    'text' => 'Opens the order to record what was handed over and collected.',
                ],
                [
                    'label' => 'Not delivered',
                    'text' => 'Takes the order off the van; it stays booked.',
                ],
                [
                    'label' => 'Settle',
                    'text' => 'Closes the sheet once every order is delivered or brought back.',
                ],
            ],
        ],
    ],
];
