<?php

// Guide: one customer's page. Labels match the screen exactly.

return [
    'title' => 'Customer page',
    'summary' => 'One customer: what they owe in total, where it comes from, and every sale linked to them.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'The figures at the top',
            'items' => [
                ['label' => 'Total Owed', 'text' => 'Everything they owe: udhaar loans plus unpaid sales and services. The same figure as on the Udhaar list.'],
                ['label' => 'Sales Outstanding', 'text' => 'The unpaid part of their sales.'],
                ['label' => 'Udhaar Loans', 'text' => 'Money given to them or received from them as udhaar, kept apart from sales.'],
                ['label' => 'Total Sales', 'text' => 'Everything they have bought, and in how many sales.'],
            ],
        ],
        [
            'id' => 'below',
            'heading' => 'Further down',
            'items' => [
                ['label' => 'Sales History', 'text' => 'Every sale linked to this customer. Tap one to open its receipt.'],
                ['label' => 'Open their Udhaar', 'text' => 'Their udhaar page, to add a loan or a repayment, or to take a payment on an unpaid sale.', 'needs' => 'udhaar'],
            ],
        ],
    ],
];
