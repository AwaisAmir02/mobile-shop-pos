<?php

// Guide: Instalments. Labels match the screen exactly.

return [
    'title' => 'Instalments',
    'summary' => 'Every sale sold on a monthly plan, with the late ones first, so you know who to call.',
    'sections' => [
        [
            'id' => 'selling',
            'heading' => 'Selling on instalments',
            'steps' => [
                'On the sales register, under Payment, choose Instalments.',
                'Enter the Down Payment taken today, how many monthly Instalments, and the First Due date. The plan shows underneath.',
                'Choose the customer, then Complete Sale.',
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Open', 'text' => 'Plans with money still to come.'],
                ['label' => 'Due now', 'text' => 'Only plans with an instalment due today or late.'],
                ['label' => 'Paid off', 'text' => 'Plans that are fully paid.'],
                ['label' => 'Status', 'text' => 'What is late and since when, then the next date and amount.'],
                ['label' => 'Left', 'text' => 'Everything still owed on that sale.'],
            ],
        ],
        [
            'id' => 'payments',
            'heading' => 'Taking a payment',
            'intro' => 'Open the customer and use Record Payment on the sale, as for any amount owed. A payment always pays the earliest instalment first. Late instalments go into the customer\'s WhatsApp payment reminder; ones not due yet are only mentioned.',
        ],
    ],
];
