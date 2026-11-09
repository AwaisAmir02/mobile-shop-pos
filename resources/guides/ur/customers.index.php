<?php

// Guide (Roman Urdu): Customers list. Labels stay in English, exactly as on screen.

return [
    'title' => 'Customers',
    'summary' => 'Aap ke customers, aur har ek par kitna baqi hai. Yahan banayein, ya sale karte waqt seedha register se.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Search', 'text' => 'Naam ya phone number se customer dhoondein.'],
                ['label' => 'Balance', 'text' => 'Due: itne paise unhon ne dene hain, udhaar, unpaid sales aur services mila kar. Advance: unhon ne pehle se de rakhe hain. Settled: koi len den baqi nahin.'],
            ],
        ],
        [
            'id' => 'adding',
            'heading' => 'Naya customer',
            'items' => [
                ['label' => 'Add Customer', 'text' => 'Sirf Name zaroori hai.'],
                ['label' => 'Phone', 'text' => 'Zaroor likhein: customer dhoondna aasaan hota hai, aur ek naam ke do logon mein farq pata chalta hai.'],
                ['label' => 'Address / Notes', 'text' => 'Kahan rehte hain, ya unke baare mein yaad rakhne wali baat.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'Har customer ka ⋯ menu',
            'items' => [
                ['label' => 'View', 'text' => 'Customer ka page: unki sales, kitna baqi hai aur unka udhaar.'],
                ['label' => 'Edit', 'text' => 'Naam, phone ya pata badlein.'],
                ['label' => 'Delete', 'text' => 'Sirf us customer ke liye jis ka koi record nahin: na sale, na udhaar, na counter service. Baqi customers rehte hain taake hisaab poora rahe.'],
            ],
        ],
    ],
];
