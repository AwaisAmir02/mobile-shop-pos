<?php

// Guide (Roman Urdu): Balance Load and its history. Labels stay in English, exactly as on screen.

return [
    'title' => 'Balance Load',
    'summary' => 'Customer ke number par balance ya package load karein, aur darj karein unhon ne kitne diye.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Balance kaise load karein',
            'steps' => [
                'Network aur Type chunein: Balance ya Package.',
                'Jis number par load karna hai woh Phone Number likhein.',
                'Customer chunein, ya Walk-in rehne dein.',
                'Amount likhein aur Service Charge dekh lein.',
                'Cash Received likhein, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => 'Service Charge', 'text' => 'Aap ki fees. Settings mein likhe commission % se khud aa jati hai, aap badal sakte hain.'],
                ['label' => 'Cash Received', 'text' => 'Customer ne abhi kitna cash diya. Total se kam ho to baqi unke zimme rehta hai.'],
                ['label' => 'Received Into', 'text' => 'Paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Amount aur Service Charge, Discount minus kar ke.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'Customer ke liye PDF receipt.'],
                ['label' => 'Log Another', 'text' => 'Agle load ke liye khaali form.'],
                ['label' => 'Balance Load History', 'text' => 'Upar wala link: saare loads.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Balance Load History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ke loads dekhein. Payment Status se chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Loaded', 'text' => 'Dikhaye gaye loads mein kitna balance load hua. Total Fees aap ki kamai, Total Owed by Customers jo abhi baqi hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: baad mein aane wala paisa darj karein. Mark as Paid poori raqam chukta kar deta hai.'],
                ['label' => 'Download', 'text' => 'Us load ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'balance-loads-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir load cancel kar deta hai. List mein Cancelled likh kar rehta hai. Yeh wapas nahin hota.', 'needs' => 'balance-loads-edit-cancel'],
                ['label' => 'New Balance Load', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
