<?php

// Guide (Roman Urdu): Orders. Labels match the screen exactly.

return [
    'title' => 'Orders',
    'summary' => 'Customers ke liye book kiye gaye orders. Delivery tak unka stock rok liya jata hai taake do dafa na bike; delivery par yeh sale ban jate hain.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                [
                    'label' => 'Book Order',
                    'text' => 'Customer ka naya order.',
                ],
                [
                    'label' => 'Load Sheets',
                    'text' => 'Booked orders ko din ke chakkar ke liye gaari par lagayein.',
                ],
                [
                    'label' => 'Status',
                    'text' => 'Booked, On a van, Delivered ya Cancelled.',
                ],
            ],
        ],
    ],
];
