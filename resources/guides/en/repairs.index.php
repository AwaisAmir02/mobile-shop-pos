<?php

// Guide: Repairs, and Repair History (which opens this guide at 'history').

return [
    'title' => 'Repairs',
    'summary' => 'Log a repair job: what it was, what you charged and what the customer paid. Repair History lists every job and what is still owed.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Logging a repair',
            'steps' => [
                'Pick the Main Category and, if you like, the Sub-Category.',
                'Write a short Description, for example "iPhone 13, screen cracked".',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter Service Charges (your labour) and Products Charges (any part used). Add a Discount if you gave one.',
                'Under Payment Status, say whether the customer has paid, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'Date', 'text' => 'Leave it empty for today, or pick an earlier day.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid. Whatever isn\'t paid shows as owed by the customer.'],
                ['label' => 'Amount Paid', 'text' => 'What the customer actually handed you.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the money went into, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Total', 'text' => 'Service Charges plus Products Charges, minus the Discount.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt to print or send to the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next job.'],
                ['label' => 'Repair History', 'text' => 'The link at the top: every repair you have logged.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Repair History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the repairs from a day or a range. Category and Payment Status narrow it further, and Clear removes the filters.'],
                ['label' => 'Total Collected', 'text' => 'What customers paid on the repairs shown. The other totals also follow the filters: what is still owed, and service and parts charges.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: the customer paid some or all of what they owed. Enter Amount Paid Now.'],
                ['label' => 'Mark as Paid', 'text' => 'The customer paid everything that was left.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that repair.'],
                ['label' => 'Edit', 'text' => 'Change the details or the charges.', 'needs' => 'repairs-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the repair. It stays on the list, marked Cancelled, and stops counting in totals. It can\'t be undone.', 'needs' => 'repairs-edit-cancel'],
                ['label' => 'New Repair', 'text' => 'Back to the form to log another job.'],
            ],
        ],
    ],
];
