<?php

// Guide: Store Credit report. Labels match the screen exactly.

return [
    'title' => 'Store Credit',
    'summary' => 'Credit customers were given on returns instead of money: how much was given, spent, paid out or expired in a period, and who still holds some today. Kept entirely apart from udhaar.',
    'sections' => [
        [
            'id' => 'totals',
            'heading' => 'The totals',
            'items' => [
                ['label' => 'Held by customers', 'text' => 'All the credit customers hold today: what the shop owes them in goods.'],
                ['label' => 'Given', 'text' => 'Credit given on returns between the two dates.'],
                ['label' => 'Spent', 'text' => 'Credit used to pay for sales between the two dates.'],
                ['label' => 'Paid out / expired', 'text' => 'Credit handed over as money, and credit written off after its expiry date.'],
            ],
        ],
        [
            'id' => 'holders',
            'heading' => 'Customers holding credit',
            'intro' => 'Each customer with credit, the most first. Open one to see every entry and, where the shop hands back money, to pay credit out.',
        ],
    ],
];
