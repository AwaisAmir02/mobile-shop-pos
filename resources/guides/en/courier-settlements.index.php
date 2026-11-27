<?php

// Guide: Courier Payouts. Labels match the screen exactly.

return [
    'title' => 'Courier Payouts',
    'summary' => 'Cash on delivery your couriers collect for you. Once a parcel is booked, its cash is owed by the courier, not the customer, so it stays out of Udhaar and reminders until the courier pays you.',
    'sections' => [
        [
            'id' => 'balances',
            'heading' => 'What each courier holds',
            'intro' => 'One row per courier.',
            'items' => [
                [
                    'label' => 'Delivered, not paid',
                    'text' => 'Parcels delivered with cash collected that the courier hasn\'t paid out yet.',
                ],
                [
                    'label' => 'On the way',
                    'text' => 'Cash still to collect on parcels out for delivery.',
                ],
                [
                    'label' => 'Came back',
                    'text' => 'Refused parcels, and the return charges the courier will take off its next payout.',
                ],
            ],
        ],
        [
            'id' => 'payouts',
            'heading' => 'Recording a payout',
            'intro' => 'When the courier\'s payment reaches you, record it against its statement.',
            'items' => [
                [
                    'label' => 'Record Payout',
                    'text' => 'Tick the parcels on the courier\'s statement and copy its amounts. Each sale is paid with what was collected on it.',
                ],
                [
                    'label' => 'Payouts',
                    'text' => 'Every payout recorded. Open one to see its parcels, or cancel it if it was recorded wrongly.',
                ],
            ],
        ],
    ],
];
