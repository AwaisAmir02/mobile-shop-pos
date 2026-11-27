<?php

// Guide (Roman Urdu): Courier Payouts. Labels match the screen exactly.

return [
    'title' => 'Courier Payouts',
    'summary' => 'Cash on delivery jo aap ke courier aap ke liye wasool karte hain. Parcel book hone ke baad yeh raqam customer ki nahin, courier ki dena hai, is liye courier ke paise dene tak yeh Udhaar aur reminders mein nahin aati.',
    'sections' => [
        [
            'id' => 'balances',
            'heading' => 'Har courier ke paas kitna hai',
            'intro' => 'Har courier ki ek line.',
            'items' => [
                [
                    'label' => 'Delivered, not paid',
                    'text' => 'Deliver ho chuke parcels jin ka cash courier ne abhi aap ko nahin diya.',
                ],
                [
                    'label' => 'On the way',
                    'text' => 'Raste mein parcels par abhi wasool hone wala cash.',
                ],
                [
                    'label' => 'Came back',
                    'text' => 'Wapas aaye parcels, aur return charges jo courier agli payout se kaatega.',
                ],
            ],
        ],
        [
            'id' => 'payouts',
            'heading' => 'Payout darj karna',
            'intro' => 'Courier ki payment milne par us ke statement ke mutabiq darj karein.',
            'items' => [
                [
                    'label' => 'Record Payout',
                    'text' => 'Statement wale parcels tick karein aur us ki raqamein likhein. Har sale par us parcel ka wasool shuda cash ada ho jata hai.',
                ],
                [
                    'label' => 'Payouts',
                    'text' => 'Darj ki gayi tamam payouts. Kholein to parcels dekhein, ya ghalat darj hui ho to cancel karein.',
                ],
            ],
        ],
    ],
];
