<?php

// Guide: Courier Payout. Labels match the screen exactly.

return [
    'title' => 'Courier Payout',
    'summary' => 'One payout and the parcels it covered.',
    'sections' => [
        [
            'id' => 'cancel',
            'heading' => 'Undoing a payout',
            'intro' => 'Only if it was recorded wrongly.',
            'items' => [
                [
                    'label' => 'Cancel Payout',
                    'text' => 'Takes back the payments it made on each sale. The courier owes that cash again, and its parcels wait for a payout.',
                ],
            ],
        ],
    ],
];
