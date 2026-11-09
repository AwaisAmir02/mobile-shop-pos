<?php

// Guide: Bills (utility bill payments), and Bill Payment History (opens at 'history').

return [
    'title' => 'Bills',
    'summary' => 'Pay a customer\'s electricity, gas, phone or other bill from one of your accounts, and record what they paid you for it.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Paying a bill',
            'steps' => [
                'Pick the Category (electricity, gas…) and the Provider / Company. Region / Province narrows the list of providers.',
                'Enter the Consumer Number and Consumer Name from the bill.',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter the Bill Amount and choose Send From, the shop account paying it.',
                'Check the Service Charge, set the Payment Status, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => '+ New Provider', 'text' => 'Adds a company that isn\'t on the list yet. + New Provider (with a region) also sets its region.'],
                ['label' => 'Send From', 'text' => 'Which of your own accounts (JazzCash, bank…) pays the bill. + New Shop Account adds one.'],
                ['label' => 'Service Charge', 'text' => 'Your fee. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid: has the customer paid you? For Partial, enter the Amount Paid.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the customer\'s money went into, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Bill Amount plus Service Charge, minus the Discount.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt for the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next bill.'],
                ['label' => 'Bill Payment History', 'text' => 'The link at the top: every bill you have paid.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Bill Payment History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the bills from a day or a range. Category, Provider and Payment Status narrow it further, and Clear removes the filters.'],
                ['label' => 'Total Collected', 'text' => 'What customers paid on the bills shown. Total Owed by Customers is what they still owe.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: the customer paid some or all of what they owed. Enter Amount Paid Now.'],
                ['label' => 'Mark as Paid', 'text' => 'The customer paid everything that was left.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that bill.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'bills-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the bill payment. It stays on the list, marked Cancelled, and stops counting in totals. It can\'t be undone.', 'needs' => 'bills-edit-cancel'],
                ['label' => 'New Bill Payment', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
