<?php

// Guide (Roman Urdu): Warranty Check. Labels match the screen exactly.

return [
    'title' => 'Warranty Check',
    'summary' => 'Jab customer phone wapas laye, yahan IMEI ya invoice number se dhoondein aur foran dekhein ke warranty abhi baaki hai ya nahi.',
    'sections' => [
        [
            'id' => 'search',
            'heading' => 'Phone dhoondna',
            'steps' => [
                'Customer se IMEI poochein (phone par *#06# dial karein, ya dabbe par dekhein), ya receipt par invoice number.',
                'Box mein likhein. Poora IMEI bhi chalega aur aakhri chand digits bhi.',
                'Jo sale match kare woh neeche nazar aa jayegi.',
            ],
        ],
        [
            'id' => 'result',
            'heading' => 'Natija samajhna',
            'items' => [
                ['label' => 'Under warranty', 'text' => 'Abhi warranty hai. Kitne din baaki hain aur aakhri din bhi dikhata hai.'],
                ['label' => 'Expired', 'text' => 'Warranty khatam ho chuki hai. Kis din khatam hui, woh bhi dikhata hai.'],
                ['label' => 'No warranty', 'text' => 'Bechte waqt product par koi warranty set nahi thi.'],
                ['label' => 'Sale cancelled', 'text' => 'Woh sale cancel ho chuki hai, is liye warranty nahi.'],
                ['label' => 'Invoice', 'text' => 'Asal sale kholta hai.'],
            ],
        ],
        [
            'id' => 'setting',
            'heading' => 'Warranty kahan se aati hai',
            'intro' => 'Products screen par product mein Warranty (months) aur Warranty from (Brand ya Shop) set karein. Har unit wohi warranty rakhta hai jis ke saath bika tha, chahe baad mein product badal dein.',
        ],
    ],
];
