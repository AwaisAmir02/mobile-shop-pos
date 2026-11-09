<?php

// Guide (Roman Urdu): Dashboard. Labels stay in English, exactly as on screen.

return [
    'title' => 'Dashboard',
    'summary' => 'Ek din, mahine ya saal mein dukaan ka haal: sales, kharche, logon par aap ka baqaya aur counter services.',
    'sections' => [
        [
            'id' => 'period',
            'heading' => 'Waqt chunna',
            'items' => [
                ['label' => 'Period', 'text' => 'Day, Month ya Year. Saath wale box mein konsa din, mahina ya saal. Shuru mein aaj ka din hota hai.'],
            ],
        ],
        [
            'id' => 'figures',
            'heading' => 'Chaar figures',
            'items' => [
                ['label' => 'Net (Revenue − Expenses)', 'text' => 'Is waqt ki Sales Revenue mein se Expenses minus. Ek andaza, poora munafa nahin.'],
                ['label' => 'Sales Revenue', 'text' => 'Is waqt mein hui sales ka total, discount ke baad. Cancelled sales nahin gintin.'],
                ['label' => 'Sales Outstanding', 'text' => 'Customers par sales ka saara baqaya, kisi bhi tareekh ka. Period badalne se nahin badalta.'],
                ['label' => 'Expenses', 'text' => 'Is waqt mein darj dukaan ke kharche.'],
            ],
        ],
        [
            'id' => 'expiry',
            'heading' => 'Expiring & Expired Stock',
            'feature' => 'expiry_tracking',
            'needs' => 'stock-ins',
            'intro' => 'Aaj ki tareekh mein expire ho chuka ya hone wala stock, period chahe jo bhi ho. Poori list ke liye box par tap karein.',
        ],
        [
            'id' => 'trend',
            'heading' => 'Revenue Trend',
            'intro' => 'Waqt ke saath sales revenue, period ke hisaab se din, mahine ya saal mein. Raqam dekhne ke liye kisi dot par mouse le jayein (phone par tap karein).',
        ],
        [
            'id' => 'services',
            'heading' => 'Services & counters',
            'items' => [
                ['label' => 'Items Sold', 'text' => 'Kitne items bike, mobile, accessory aur SIM alag alag.'],
                ['label' => 'Fees and revenue rows', 'text' => 'Har counter service ki ek line: balance, wallet, SIM, bills, repairs, NADRA. Fees woh hai jo dukaan ne raqam ke ilawa kamaya.'],
                ['label' => 'Show … with nothing this period', 'text' => 'Jin lines mein is waqt kuch nahin, woh chhupi rehti hain. Yeh unhein dikhata hai; Hide the empty ones se phir chhup jati hain.'],
            ],
        ],
    ],
];
