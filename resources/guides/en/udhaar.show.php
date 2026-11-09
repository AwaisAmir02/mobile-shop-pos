<?php

// Guide: one customer's Udhaar History. Labels match the screen exactly.

return [
    'title' => 'Udhaar History',
    'summary' => 'Everything one customer owes you: their udhaar loans entry by entry, and their unpaid sales and services.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'The figures at the top',
            'items' => [
                ['label' => 'Current Balance', 'text' => 'Everything they owe right now, or what they have paid in advance.'],
                ['label' => 'Udhaar Loans', 'text' => 'The udhaar part, from the table below.'],
                ['label' => 'Unpaid Sales & Services', 'text' => 'The unpaid part of their sales and counter services, listed further down.'],
            ],
        ],
        [
            'id' => 'loans',
            'heading' => 'Udhaar loans',
            'items' => [
                ['label' => 'Add Transaction', 'text' => 'Udhaar Given when you give them credit, Repayment when they pay you back.'],
                ['label' => 'Running Balance', 'text' => 'Their loan balance after each entry.'],
                ['label' => 'Entered By', 'text' => 'Who added the entry.'],
            ],
        ],
        [
            'id' => 'fixing',
            'heading' => 'Fixing a wrong entry (the ⋯ menu)',
            'items' => [
                ['label' => 'Edit', 'text' => 'Change the type, amount, date or note of the entry.'],
                ['label' => 'Reverse', 'text' => 'Adds an opposite entry dated today, so the mistake and its correction both stay in the history. Nothing is edited or deleted.'],
                ['label' => 'Cancel', 'text' => 'Marks the entry Cancelled. It stops counting toward the balance but stays visible for the record. It can\'t be undone.'],
            ],
        ],
        [
            'id' => 'other',
            'heading' => 'Other Amounts Owed',
            'items' => [
                ['label' => 'Source', 'text' => 'Which sale or counter service the money is owed on.'],
                ['label' => 'Record Payment', 'text' => 'Take a payment on that sale or service: Amount Paid Now and how it was paid. The balance updates straight away.'],
            ],
        ],
    ],
];
