<?php

// Guide: Job Cards. Labels match the screen exactly.

return [
    'title' => 'Job Cards',
    'summary' => 'Units brought in for repair, and where each job stands.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'Jobs',
            'intro' => 'Search by customer, registration or serial number.',
            'items' => [
                [
                    'label' => 'Under warranty',
                    'text' => 'Jobs whose warranty is still running.',
                ],
            ],
        ],
    ],
];
