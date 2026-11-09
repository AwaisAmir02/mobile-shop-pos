<?php

// Guide: SIM Sale, and SIM Sale History (opens at 'history').

return [
    'title' => 'SIM Sale',
    'summary' => 'Record a new SIM, an eSIM or a duplicate SIM issued to a customer, and what they paid.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Recording a SIM sale',
            'steps' => [
                'Pick the Network, the Plan Type (Prepaid or Postpaid) and the SIM Form (Physical SIM or eSIM).',
                'Enter the SIM Number being issued or activated.',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter the Amount and check the Service Fee.',
                'Set the Payment Status, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'Duplicate SIM (replacement for a lost SIM)', 'text' => 'Tick it when the customer lost their SIM and you issued a replacement on the same number.'],
                ['label' => 'Service Fee', 'text' => 'Your fee. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid: has the customer paid you? For Partial, enter the Amount Paid.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the money went into, for your ledger.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt for the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next SIM.'],
                ['label' => 'SIM Sale History', 'text' => 'The link at the top: every SIM sale.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'SIM Sale History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the SIM sales from a day or a range. Network and Payment Status narrow it, and Clear removes the filters.'],
                ['label' => 'Total Revenue', 'text' => 'The total of the SIM sales shown. Total Owed by Customers is what is still unpaid.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later. Mark as Paid settles the whole amount.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that sale.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'sim-sales-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the SIM sale. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'sim-sales-edit-cancel'],
                ['label' => 'New SIM Sale', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
