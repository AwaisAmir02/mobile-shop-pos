<?php

// Guide (Roman Urdu): Used Phone Buyback. Labels match the screen exactly.

return [
    'title' => 'Used Phone Buyback',
    'summary' => 'Customer se purana phone khareedein aur seedha stock mein daal kar dobara bechein. Jo qeemat aap dete hain wohi us ki cost banti hai, is liye bikne par munafa sahi aata hai.',
    'sections' => [
        [
            'id' => 'buy',
            'heading' => 'Phone khareedna',
            'steps' => [
                'Bechne wale ka naam likhein. Us ka phone number aur CNIC bhi likhein: agar kabhi phone par sawal uthe to pata ho kis se liya tha.',
                'Brand, model aur IMEI likhein (phone par *#06# dial karein). IMEI dabbe se mila lein.',
                'Condition chunein, aur battery health, PTA status, box aur charger likhein.',
                'Jo qeemat di woh aur jis qeemat par bechna hai woh likhein.',
                'Paisa kahan se diya (cash ya account) chunein aur Buy and add to stock dabayein.',
            ],
        ],
        [
            'id' => 'what-happens',
            'heading' => 'Phir kya hota hai',
            'items' => [
                ['label' => 'Products', 'text' => 'Phone "Brand Model (Used)" naam se product ban jata hai, stock mein ek, Sales screen par bechne ke liye tayyar.'],
                ['label' => 'Cash & Balances', 'text' => 'Di gayi qeemat chune hue cash ya account se nikal jati hai.'],
                ['label' => 'IMEI', 'text' => 'Jo phone pehle se stock mein hai woh dobara nahi khareeda ja sakta. Jo phone aap pehle bech chuke hain woh apne purane product par wapas aa jata hai.'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'In stock', 'text' => 'Khareeda hai, abhi bika nahi.'],
                ['label' => 'Sold', 'text' => 'Kisi customer ko bik chuka hai.'],
                ['label' => 'Trade-in on', 'text' => 'Woh phone jo customer ne kisi sale ki payment ke hissay mein diya (New Sale screen, Add trade-in phone). Yeh sirf us sale ko cancel karne se wapas hota hai, jis se phone customer ko lauta diya jata hai.'],
                ['label' => 'Cancel', 'text' => 'Ghalti se ki gayi buyback wapas karta hai: phone stock se nikal jata hai aur payment wapas ho jati hai. Sirf us phone ke liye jo abhi bika nahi.', 'needs' => 'buybacks-cancel'],
            ],
        ],
    ],
];
