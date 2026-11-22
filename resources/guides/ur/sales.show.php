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
            'id' => 'returns',
            'heading' => 'Returns',
            'needs' => 'sales-returns',
            'items' => [
                ['label' => 'Return Items', 'text' => 'Is sale ka kuch ya saara saman wapas lene ki screen kholta hai. Har return ka apna number (RN-) hota hai aur usi din gina jata hai jis din hua.'],
                ['label' => 'Exchange', 'text' => 'New Sale ko exchange mode mein kholta hai: is sale se kya wapas aa raha hai chunein, phir customer jo is ki jagah le raha hai woh add karein (size badalna bas doosra size chunna hai). Wapas aane wala saman naye items ki payment karta hai; farq customer deta hai, ya bachi raqam paison ya store credit mein wapas milti hai. Return aur nayi sale ek saath save hoti hain. Woh return cancel karne se poora exchange wapas ho jata hai.'],
                ['label' => 'Credit note PDF', 'text' => 'Return ka apna document, print karne ya bhejne ke liye: kya wapas aaya, baqi raqam mein se kitna kata aur kitna wapas diya.'],
                ['label' => 'Cancel return', 'text' => 'Ghalti se kiya gaya return wapas karta hai: saman dobara stock se nikal jata hai. Agar woh saman dobara bik chuka ho to mumkin nahi.'],
            ],
        ],
        [
            'id' => 'prescription',
            'heading' => 'Prescription register',
            'intro' => 'Jab sale mein Prescription only ya Controlled dawai ho to yeh nazar aata hai: har dawai ka register number, patient aur prescriber (ya emergency ki wajah). Add details us sale ki tafseel bharta hai jo register par nahin bani, maslan WhatsApp order; Edit details unhe theek karta hai. View prescription photo photo kholta hai, sirf shop ke signed-in users ke liye.',
        ],
        [
            'id' => 'collecting',
            'heading' => 'Baqi raqam lena',
            'intro' => 'Is sale ki baad mein payment lene ke liye Sales History mein sale ki line par Record Payment, ya customer ke Udhaar page par jayein.',
        ],
    ],
];
