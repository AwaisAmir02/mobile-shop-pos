<?php

// Guide: Balance Load (mobile credit and packages), and Balance Load History (opens at 'history').

return [
    'title' => 'Balance Load',
    'summary' => 'Load mobile balance or a package onto a customer\'s number, and record what they paid you.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Loading balance',
            'steps' => [
                'Pick the Network and the Type: Balance or Package.',
                'Enter the Phone Number being loaded.',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter the Amount and check the Service Charge.',
                'Enter the Cash Received, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'Service Charge', 'text' => 'Your fee. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Cash Received', 'text' => 'How much cash the customer handed you just now. Less than the total means they still owe the rest.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the money went into, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Amount plus Service Charge, minus the Discount.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt for the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next load.'],
                ['label' => 'Balance Load History', 'text' => 'The link at the top: every load.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Balance Load History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the loads from a day or a range. Payment Status narrows it, and Clear removes the filters.'],
                ['label' => 'Total Loaded', 'text' => 'The balance loaded on the loads shown. Total Fees is what you earned, Total Owed by Customers what is still unpaid.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later. Mark as Paid settles the whole amount.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that load.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'balance-loads-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the load. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'balance-loads-edit-cancel'],
                ['label' => 'New Balance Load', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
