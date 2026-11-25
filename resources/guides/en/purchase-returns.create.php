<?php

// Guide: Return to Vendor. Labels match the screen exactly.

return [
    'title' => 'Return to Vendor',
    'summary' => 'Pick the vendor, then how many of each of their batches go back. Each line comes out of the batch it was bought in, at that batch\'s cost.',
    'sections' => [
        [
            'id' => 'lines',
            'heading' => 'What is going back',
            'intro' => 'Only batches bought from this vendor and still in stock are listed, with their expiry and batch number.',
            'items' => [
                [
                    'label' => 'Vendor',
                    'text' => 'Who the goods go back to.',
                ],
                [
                    'label' => 'Find product',
                    'text' => 'Narrow the list of batches.',
                ],
            ],
        ],
        [
            'id' => 'note',
            'heading' => 'Debit note',
            'items' => [
                [
                    'label' => 'Reason',
                    'text' => 'Expired, damaged, wrong item, excess or other.',
                ],
                [
                    'label' => 'Save Return',
                    'text' => 'Stock goes down, and the value comes off what you owe. Anything over that stays as credit with the vendor.',
                ],
            ],
        ],
    ],
];
