<?php

// Guide (Roman Urdu): one vendor's page. Labels stay in English, exactly as on screen.

return [
    'title' => 'Vendor page',
    'summary' => 'Ek supplier: aap par unke kitne baqi hain, un se kya khareeda, aur har delivery.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'Upar wali figures',
            'items' => [
                ['label' => 'Total Owed to Vendor', 'text' => 'Un se aaye maal ke kitne paise aap par abhi baqi hain.'],
                ['label' => 'Total Purchase Cost', 'text' => 'Un se khareeda gaya sab kuch, cost price par.'],
                ['label' => 'Stock Entries', 'text' => 'Is vendor se Stock In ki kitni lines aayin.'],
                ['label' => 'Total Units Purchased', 'text' => 'Piece mein gine jane wale items.'],
                ['label' => 'Weight, Volume & Length', 'text' => 'Kg, litre ya metre mein khareede gaye items, alag dikhaye gaye.', 'feature' => 'measured_units'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Stock Entries',
            'items' => [
                ['label' => 'Payment', 'text' => 'Har delivery ke liye Paid, Partial ya Unpaid. Owed woh hai jo us par abhi dena baqi hai.'],
                ['label' => 'Record payments in Stock In', 'text' => 'Is vendor ke liye Stock In History kholta hai, jahan aap unhein di gayi payment darj karte hain.', 'needs' => 'stock-ins'],
            ],
        ],
    ],
];
