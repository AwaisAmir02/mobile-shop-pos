<?php

// Guide (Roman Urdu): Sale receipt. Labels stay in English, exactly as on screen.

return [
    'title' => 'Sale receipt',
    'summary' => 'Ek sale ki tafseel: kya bika, total kitna hua, kitna mila aur kitna baqi hai. Sale ke foran baad yeh Sale complete box ke saath khulti hai.',
    'sections' => [
        [
            'id' => 'after-sale',
            'heading' => 'Sale ke foran baad',
            'items' => [
                ['label' => 'Sale complete', 'text' => 'Total, aur ya to Paid in full, ya kitna baqi hai aur kis se.'],
                ['label' => 'New Sale', 'text' => 'Agle customer ke liye wapas register par. Enter dabane se bhi yahi hota hai.'],
                ['label' => 'Download invoice', 'text' => 'PDF invoice, print karne ya customer ko WhatsApp par bhejne ke liye.'],
            ],
        ],
        [
            'id' => 'reading',
            'heading' => 'Receipt parhna',
            'items' => [
                ['label' => 'Served by', 'text' => 'Sale kis ne ki.'],
                ['label' => 'Subtotal, Discount, Total', 'text' => 'Items ka jor, poore bill par discount agar diya, aur customer ko kitna dena tha.'],
                ['label' => 'Paid', 'text' => 'Ab tak kitna mila. Badge par Paid, Partial ya Unpaid likha hota hai.'],
                ['label' => 'Paid by', 'text' => 'Is sale ki har payment aur woh kaise aayi, agar darj ki gayi ho.'],
                ['label' => 'Download PDF', 'text' => 'Wahi PDF invoice, kabhi bhi.'],
            ],
        ],
        [
            'id' => 'changes',
            'heading' => 'Sale badalna ya cancel karna',
            'needs' => 'sales-edit-cancel',
            'items' => [
                ['label' => 'Edit', 'text' => 'Sale khulti hai taake items, quantity ya qeemat badal sakein. Stock aur customer ka balance bhi saath badal jata hai.'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir sale cancel kar deta hai. Stock wapas aa jata hai aur sale kisi total, report ya balance mein nahin ginti. List mein Cancelled likh kar rehti hai, aur yeh wapas nahin hota. Wajah likhna zaroori nahin.'],
            ],
        ],
        [
            'id' => 'collecting',
            'heading' => 'Baqi raqam lena',
            'intro' => 'Is sale ki baad mein payment lene ke liye Sales History mein sale ki line par Record Payment, ya customer ke Udhaar page par jayein.',
        ],
    ],
];
