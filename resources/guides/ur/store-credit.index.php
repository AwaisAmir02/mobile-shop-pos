<?php

// Guide (Roman Urdu): Store Credit report. Labels stay in English, exactly as on screen.

return [
    'title' => 'Store Credit',
    'summary' => 'Woh credit jo customers ko return par paison ki jagah diya gaya: kisi muddat mein kitna diya gaya, kharch hua, paison mein diya gaya ya expire hua, aur aaj kis ke paas baqi hai. Udhaar se bilkul alag.',
    'sections' => [
        [
            'id' => 'totals',
            'heading' => 'Totals',
            'items' => [
                ['label' => 'Held by customers', 'text' => 'Aaj tamam customers ke paas jitna credit hai: shop ne unhe itne ka saman dena hai.'],
                ['label' => 'Given', 'text' => 'Dono tareekhon ke darmiyan returns par diya gaya credit.'],
                ['label' => 'Spent', 'text' => 'Dono tareekhon ke darmiyan sales ki payment mein istemal hua credit.'],
                ['label' => 'Paid out / expired', 'text' => 'Paison ki surat mein diya gaya credit, aur expiry ke baad khatam kiya gaya credit.'],
            ],
        ],
        [
            'id' => 'holders',
            'heading' => 'Credit wale customers',
            'intro' => 'Har customer jis ke paas credit hai, sab se zyada wala pehle. Kisi ko khol kar har entry dekhein aur, agar shop paise wapas deti hai, credit paison mein dein.',
        ],
    ],
];
