<?php

// Guide (Roman Urdu): Daraz. Labels match the screen exactly.

return [
    'title' => 'Daraz',
    'summary' => 'Seller Center ki files se apne Daraz orders aur payouts layein. Daraz ke orders ki raqam payout tak Daraz par baqi hoti hai, is liye yeh kisi customer ka udhaar nahin banti.',
    'sections' => [
        [
            'id' => 'orders',
            'heading' => 'Orders',
            'intro' => 'Seller Center (Orders › Manage Orders) se orders export karein aur file waisi hi upload karein.',
            'items' => [
                [
                    'label' => 'Import orders',
                    'text' => 'Jis order ka Seller SKU yahan kisi product ka barcode ho, woh foran bik jata hai aur stock se nikal jata hai. Nayi file orders ko delivered ya returned kar deti hai. Wohi file dobara lane se kuch nahin badalta.',
                ],
                [
                    'label' => 'Seller SKUs to match',
                    'text' => 'Woh orders jin ka Seller SKU yahan barcode nahin. Ek dafa product chun lein; agli files khud match ho jayengi.',
                ],
            ],
        ],
        [
            'id' => 'payouts',
            'heading' => 'Payouts',
            'intro' => 'Jab Daraz paise de, Seller Center (Finance) se account statement export karein.',
            'items' => [
                [
                    'label' => 'Read statement',
                    'text' => 'Har order par Daraz ne kitna diya, kitne charges kaate aur kitna tax roka, aur file ka apna total milane ke liye.',
                ],
                [
                    'label' => 'Record payout',
                    'text' => 'Courier ki tarah ek payout darj hoti hai: har order ada, charges kharchon mein, aur Cash & Balances mein sirf woh raqam jo account mein aayi.',
                ],
            ],
        ],
    ],
];
