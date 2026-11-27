<?php

// Guide: Gift Vouchers. Labels match the screen exactly.

return [
    'title' => 'Gift Vouchers',
    'summary' => 'Sold for money now and spent at New Sale with the code, by whoever holds it.',
    'sections' => [
        [
            'id' => 'sell',
            'heading' => 'Selling',
            'intro' => 'Each voucher gets its own code.',
            'items' => [
                [
                    'label' => 'Left',
                    'text' => 'What is still to spend, after the bills that used it.',
                ],
                [
                    'label' => 'Cancel',
                    'text' => 'Only while none of it is spent.',
                ],
            ],
        ],
    ],
];
