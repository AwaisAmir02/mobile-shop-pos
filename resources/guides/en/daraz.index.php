<?php

// Guide: Daraz. Labels match the screen exactly.

return [
    'title' => 'Daraz',
    'summary' => 'Bring your Daraz orders and payouts in from Seller Center files. Daraz owes you for its orders until it pays out, so they never show as a customer\'s udhaar.',
    'sections' => [
        [
            'id' => 'orders',
            'heading' => 'Orders',
            'intro' => 'Export orders in Seller Center (Orders › Manage Orders) and upload the file as it comes.',
            'items' => [
                [
                    'label' => 'Import orders',
                    'text' => 'An order whose Seller SKU is a product\'s barcode here is sold at once, out of your stock. Importing a newer file moves orders to delivered or returned. The same file twice changes nothing.',
                ],
                [
                    'label' => 'Seller SKUs to match',
                    'text' => 'Orders waiting because their Seller SKU isn\'t a barcode here. Pick the product once; every later file matches it by itself.',
                ],
            ],
        ],
        [
            'id' => 'payouts',
            'heading' => 'Payouts',
            'intro' => 'Export your account statement in Seller Center (Finance) when Daraz pays you.',
            'items' => [
                [
                    'label' => 'Read statement',
                    'text' => 'Shows what Daraz credited for each order, its charges and the tax it held, and the file\'s own total to check against.',
                ],
                [
                    'label' => 'Record payout',
                    'text' => 'Records it as one payout, like a courier\'s: each order is paid, the charges go to expenses, and only what reached your account shows in Cash & Balances.',
                ],
            ],
        ],
    ],
];
