<?php

// Guide: Dashboard. Labels match the screen exactly.

return [
    'title' => 'Dashboard',
    'summary' => 'How the shop is doing for a day, a month or a year: sales, expenses, money owed to you and the counter services.',
    'sections' => [
        [
            'id' => 'period',
            'heading' => 'Choosing the period',
            'items' => [
                ['label' => 'Period', 'text' => 'Day, Month or Year. The box next to it picks which one. It starts on today.'],
            ],
        ],
        [
            'id' => 'figures',
            'heading' => 'The four figures',
            'items' => [
                ['label' => 'Net (Revenue − Expenses)', 'text' => 'Sales Revenue minus Expenses for the period. A quick sense of the period, not your full profit.'],
                ['label' => 'Sales Revenue', 'text' => 'The total of the sales made in the period, after discounts. Cancelled sales don\'t count.'],
                ['label' => 'Sales Outstanding', 'text' => 'Everything customers still owe on sales, from any date. It doesn\'t change with the period.'],
                ['label' => 'Expenses', 'text' => 'Shop expenses recorded for the period.'],
            ],
        ],
        [
            'id' => 'expiry',
            'heading' => 'Expiring & Expired Stock',
            'feature' => 'expiry_tracking',
            'needs' => 'stock-ins',
            'intro' => 'Stock that has expired or is about to, as of today, whatever period is picked. Tap the box to open the full list.',
        ],
        [
            'id' => 'trend',
            'heading' => 'Revenue Trend',
            'intro' => 'Sales revenue over time, by day, month or year to match the period. Point at a dot (or tap it on a phone) to see the amount.',
        ],
        [
            'id' => 'services',
            'heading' => 'Services & counters',
            'items' => [
                ['label' => 'Items Sold', 'text' => 'How many items were sold, split into mobiles, accessories and SIMs.'],
                ['label' => 'Fees and revenue rows', 'text' => 'One row for each counter service you use: balance, wallet, SIMs, bills, repairs, NADRA. Fees are what the shop earned on top of the amount handled.'],
                ['label' => 'Show … with nothing this period', 'text' => 'Rows with nothing in the period are folded away. This shows them; Hide the empty ones folds them again.'],
            ],
        ],
    ],
];
