<?php

// Guide (Roman Urdu): SIM Sale and its history. Labels stay in English, exactly as on screen.

return [
    'title' => 'SIM Sale',
    'summary' => 'Customer ko di gayi nayi SIM, eSIM ya duplicate SIM darj karein, aur unhon ne kitne diye.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'SIM sale kaise darj karein',
            'steps' => [
                'Network, Plan Type (Prepaid ya Postpaid) aur SIM Form (Physical SIM ya eSIM) chunein.',
                'Jo number jari ya activate ho raha hai woh SIM Number likhein.',
                'Customer chunein, ya Walk-in rehne dein.',
                'Amount likhein aur Service Fee dekh lein.',
                'Payment Status set karein, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => 'Duplicate SIM (replacement for a lost SIM)', 'text' => 'Tick karein jab customer ki SIM gum ho gayi ho aur aap ne usi number par nayi SIM di ho.'],
                ['label' => 'Service Fee', 'text' => 'Aap ki fees. Settings mein likhe commission % se khud aa jati hai, aap badal sakte hain.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid: customer ne aap ko paise diye? Partial ho to Amount Paid likhein.'],
                ['label' => 'Received Into', 'text' => 'Paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'Customer ke liye PDF receipt.'],
                ['label' => 'Log Another', 'text' => 'Agli SIM ke liye khaali form.'],
                ['label' => 'SIM Sale History', 'text' => 'Upar wala link: saari SIM sales.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'SIM Sale History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ki SIM sales dekhein. Network aur Payment Status se chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Revenue', 'text' => 'Dikhayi gayi SIM sales ka total. Total Owed by Customers jo abhi baqi hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: baad mein aane wala paisa darj karein. Mark as Paid poori raqam chukta kar deta hai.'],
                ['label' => 'Download', 'text' => 'Us sale ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'sim-sales-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir SIM sale cancel kar deta hai. List mein Cancelled likh kar rehti hai. Yeh wapas nahin hota.', 'needs' => 'sim-sales-edit-cancel'],
                ['label' => 'New SIM Sale', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
