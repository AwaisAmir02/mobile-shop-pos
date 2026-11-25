<?php

// Guide: Purchase Returns. Labels match the screen exactly.

return [
    'title' => 'Purchase Returns',
    'summary' => 'Stock sent back to vendors, each with a debit note. The goods leave stock at the cost they were bought at, and that value comes off what you owe the vendor.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                [
                    'label' => 'Return to Vendor',
                    'text' => 'Record new goods going back.',
                ],
                [
                    'label' => 'Debit note PDF',
                    'text' => 'The note to send with the goods, item by item.',
                ],
                [
                    'label' => 'Cancel',
                    'text' => 'If the vendor refused them: the goods come back into stock and are owed for again.',
                ],
            ],
        ],
    ],
];
