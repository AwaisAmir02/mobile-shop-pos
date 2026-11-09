<?php

// Guide: NADRA Verification, and its history (opens at 'history').

return [
    'title' => 'NADRA Verification',
    'summary' => 'Record a SIM ownership check or biometric verification done for a customer, and what they paid.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Recording a verification',
            'steps' => [
                'Enter the Phone Number and the CNIC Number.',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter the Amount and check the Service Fee.',
                'Set the Payment Status, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'CNIC Number', 'text' => 'In the usual xxxxx-xxxxxxx-x form.'],
                ['label' => 'Service Fee', 'text' => 'Your fee. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid: has the customer paid you? For Partial, enter the Amount Paid.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the money went into, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Amount plus Service Fee, minus the Discount.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt for the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next one.'],
                ['label' => 'NADRA Verification History', 'text' => 'The link at the top: every verification.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'NADRA Verification History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the verifications from a day or a range. Payment Status narrows it, and Clear removes the filters.'],
                ['label' => 'Total Collected', 'text' => 'What customers paid on the verifications shown. Total Owed by Customers is what is still unpaid.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later. Mark as Paid settles the whole amount.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that verification.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'nadra-verifications-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels it. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'nadra-verifications-edit-cancel'],
                ['label' => 'New Verification', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
