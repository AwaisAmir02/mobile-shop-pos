<?php

// Guide (Roman Urdu): Party Ledger. Labels stay in English, exactly as on screen.

return [
    'title' => 'Party Ledger',
    'summary' => 'Ek customer ya vendor ka statement, kisi bhi tareekhon ka: har entry running balance ke saath. Hisaab barabar karne ya unhein copy bhejne ke liye.',
    'sections' => [
        [
            'id' => 'choosing',
            'heading' => 'Statement kaise nikalein',
            'steps' => [
                'Party type mein Customers ya Vendors chunein.',
                'Customer ya vendor chunein.',
                'Tareekhein chunein: This month, This year, All time, ya apni From aur To.',
            ],
            'items' => [
                ['label' => 'Vendors', 'text' => 'Sirf un logon ke liye jinhein Vendors dekhne ki ijazat hai.', 'needs' => 'vendors'],
            ],
        ],
        [
            'id' => 'reading',
            'heading' => 'Statement parhna',
            'intro' => 'Har qism ka paisa apna alag balance rakhta hai. Inhein kabhi jora nahin jata, taake saaf pata chale raqam kahan se aayi.',
            'items' => [
                ['label' => 'Sales outstanding', 'text' => 'Customer: unki sales ka baqi hissa.'],
                ['label' => 'Udhaar balance', 'text' => 'Customer: diya gaya aur wapas mila udhaar.'],
                ['label' => 'Owed to vendor', 'text' => 'Vendor: khareedari aur us ke liye di gayi payment.'],
                ['label' => 'Opening & adjustments', 'text' => 'HisabOKitab se pehle ke purane balance, aur durustiyan, jo Add Entry se daali gayin.'],
                ['label' => 'Balance brought forward', 'text' => 'From tareekh se ek din pehle ka balance. Closing balance aakhir mein jo bachta hai.'],
                ['label' => 'Other amounts owed', 'text' => 'Customer: counter services ke baqi paise, sirf maloomat ke liye. Yeh payment Udhaar screen par lein.'],
                ['label' => 'Export', 'text' => 'Statement PDF ya Excel file mein download karein, rakhne ya bhejne ke liye.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Opening balance aur adjustments',
            'items' => [
                ['label' => 'Add Entry', 'text' => 'Pehli entry Opening balance hoti hai, yaani shuru karne se pehle kitna baqi tha. Uske baad entries Adjustment hoti hain.'],
                ['label' => 'Direction', 'text' => 'Entry se unka baqaya barhta hai ya ghatta hai (vendor ke liye: aap par baqaya barhta hai ya ghatta). Options mein saaf likha hai.'],
                ['label' => 'Note', 'text' => 'Zaroori: likhein entry kyun daali ja rahi hai.'],
                ['label' => 'Cancel', 'text' => 'Opening ya adjustment entry par: nazar aati rehti hai, Cancelled likh kar, lekin ginti mein nahin aati. Yeh wapas nahin hota.'],
            ],
        ],
    ],
];
