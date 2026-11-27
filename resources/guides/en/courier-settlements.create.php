<?php

// Guide: Record a Courier Payout. Labels match the screen exactly.

return [
    'title' => 'Record a Courier Payout',
    'summary' => 'One payout from a courier covers many parcels. Copy its payment statement: what it collected on each parcel, what it charged, and what it withheld.',
    'sections' => [
        [
            'id' => 'parcels',
            'heading' => 'The parcels',
            'intro' => 'Delivered and returned parcels waiting for a payout, oldest first.',
            'items' => [
                [
                    'label' => 'Cash collected',
                    'text' => 'Starts at the cash to collect. If the courier paid less, enter what it paid: the rest stays owed by the customer.',
                ],
                [
                    'label' => 'Delivery charge',
                    'text' => 'What the courier charged to deliver this parcel.',
                ],
                [
                    'label' => 'Return charge',
                    'text' => 'What it charged to bring a refused parcel back.',
                ],
            ],
        ],
        [
            'id' => 'payout',
            'heading' => 'The payout',
            'intro' => 'Paid to you is the cash collected less everything taken off.',
            'items' => [
                [
                    'label' => 'Tax withheld',
                    'text' => 'Income tax and sales tax the courier kept back. Ask your accountant about claiming it.',
                ],
                [
                    'label' => 'Paid into',
                    'text' => 'Cash, or the account the money arrived in. Cash & Balances shows only this net amount.',
                ],
            ],
        ],
    ],
];
