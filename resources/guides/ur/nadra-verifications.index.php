<?php

// Guide (Roman Urdu): NADRA Verification and its history. Labels stay in English, exactly as on screen.

return [
    'title' => 'NADRA Verification',
    'summary' => 'Customer ke liye ki gayi SIM ownership check ya biometric verification darj karein, aur unhon ne kitne diye.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Verification kaise darj karein',
            'steps' => [
                'Phone Number aur CNIC Number likhein.',
                'Customer chunein, ya Walk-in rehne dein.',
                'Amount likhein aur Service Fee dekh lein.',
                'Payment Status set karein, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => 'CNIC Number', 'text' => 'Aam tareeqe se, xxxxx-xxxxxxx-x.'],
                ['label' => 'Service Fee', 'text' => 'Aap ki fees. Settings mein likhe commission % se khud aa jati hai, aap badal sakte hain.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid: customer ne aap ko paise diye? Partial ho to Amount Paid likhein.'],
                ['label' => 'Received Into', 'text' => 'Paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Amount aur Service Fee, Discount minus kar ke.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'Customer ke liye PDF receipt.'],
                ['label' => 'Log Another', 'text' => 'Agli verification ke liye khaali form.'],
                ['label' => 'NADRA Verification History', 'text' => 'Upar wala link: saari verifications.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'NADRA Verification History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ki verifications dekhein. Payment Status se chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Collected', 'text' => 'Dikhayi gayi verifications par customers ne kitna diya. Total Owed by Customers jo abhi baqi hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: baad mein aane wala paisa darj karein. Mark as Paid poori raqam chukta kar deta hai.'],
                ['label' => 'Download', 'text' => 'Us verification ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'nadra-verifications-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir cancel kar deta hai. List mein Cancelled likh kar rehti hai. Yeh wapas nahin hota.', 'needs' => 'nadra-verifications-edit-cancel'],
                ['label' => 'New Verification', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
