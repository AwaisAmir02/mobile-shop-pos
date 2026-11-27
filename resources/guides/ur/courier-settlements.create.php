<?php

// Guide (Roman Urdu): Record a Courier Payout. Labels match the screen exactly.

return [
    'title' => 'Record a Courier Payout',
    'summary' => 'Courier ki ek payout mein kai parcels hote hain. Us ka payment statement dekh kar likhein: har parcel par kitna wasool hua, kitna charge kiya, aur kitna tax roka.',
    'sections' => [
        [
            'id' => 'parcels',
            'heading' => 'Parcels',
            'intro' => 'Deliver aur wapas aaye parcels jo payout ke muntazir hain, purane pehle.',
            'items' => [
                [
                    'label' => 'Cash collected',
                    'text' => 'Shuru mein wasool hone wali raqam. Courier ne kam diya ho to jitna diya woh likhein: baqi customer ke zimme rahega.',
                ],
                [
                    'label' => 'Delivery charge',
                    'text' => 'Is parcel ki delivery ka courier charge.',
                ],
                [
                    'label' => 'Return charge',
                    'text' => 'Wapas aaye parcel ko lautane ka charge.',
                ],
            ],
        ],
        [
            'id' => 'payout',
            'heading' => 'Payout',
            'intro' => 'Paid to you yaani wasool shuda cash minus jo kuch kaata gaya.',
            'items' => [
                [
                    'label' => 'Tax withheld',
                    'text' => 'Income tax aur sales tax jo courier ne rok liya. Claim karne ke liye apne accountant se poochein.',
                ],
                [
                    'label' => 'Paid into',
                    'text' => 'Cash, ya woh account jis mein paise aaye. Cash & Balances mein sirf yahi net raqam aati hai.',
                ],
            ],
        ],
    ],
];
