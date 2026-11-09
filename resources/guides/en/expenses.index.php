<?php

// Guide: Expenses. Labels match the screen exactly.

return [
    'title' => 'Expenses',
    'summary' => 'The shop\'s running costs: rent, electricity, tea, transport. They come off your revenue on the Dashboard and in reports.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Log an Expense',
            'steps' => [
                'Write a Description, for example "Shop electricity bill".',
                'Enter the Amount and the Date.',
                'Press Save. It appears in the list below.',
            ],
            'items' => [
                ['label' => 'Paid From', 'text' => 'Cash, or the account the money left from, for your ledger.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the expenses from a day or a range. Clear goes back to all of them.'],
                ['label' => 'Total Expenses', 'text' => 'The total of the expenses shown.'],
                ['label' => 'Status', 'text' => 'Active, or Cancelled. A cancelled expense stays on the list but no longer counts.'],
                ['label' => 'From Payroll', 'text' => 'Salaries paid through Payroll land here by themselves. Change them in Payroll, not here. Advance written off works the same way.', 'feature' => 'payroll'],
                ['label' => 'Edit', 'text' => 'In the ⋯ menu: fix the description, amount or date.', 'needs' => 'expenses-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the expense. It can\'t be undone.', 'needs' => 'expenses-edit-cancel'],
            ],
        ],
    ],
];
