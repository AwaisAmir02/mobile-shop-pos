<?php

// Guide: Wallet Load (JazzCash, Easypaisa… cash in and cash out), and Wallet Load History (opens at 'history').

return [
    'title' => 'Wallet Load',
    'summary' => 'Mobile wallet transfers for customers. Cash In: the customer gives you cash and you send it from your wallet. Cash Out: they send money to your wallet and you hand them cash.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Recording a transfer',
            'steps' => [
                'At the top, pick Cash In or Cash Out.',
                'Choose your account: Send From for Cash In, Received Into for Cash Out.',
                'Enter the Account Name and the Account / Mobile Number of the other person\'s wallet.',
                'Enter the Amount and check the Service Charge.',
                'Under Payment, enter what the customer has paid you so far, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'Service Charge', 'text' => 'Your fee. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Fee is already included in the amount above', 'text' => 'Tick it when the customer\'s amount already includes your fee. The line below it shows an example of what moves and what you keep.'],
                ['label' => 'Cash Received', 'text' => 'For Cash In: how much cash the customer handed you. The status (Paid, Partial or Unpaid) follows from it.'],
                ['label' => 'Amount Received in Account', 'text' => 'For Cash Out: how much the customer sent into your account.'],
                ['label' => 'Still Owed by Customer', 'text' => 'Shows when less than the total has come in: the difference still to settle with the customer. For Cash Out it is called Still Owed to Customer.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'A PDF receipt for the customer.'],
                ['label' => 'Log Another', 'text' => 'An empty form for the next transfer.'],
                ['label' => 'Wallet Load History', 'text' => 'The link at the top: every transfer.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Wallet Load History',
            'items' => [
                ['label' => 'Cash in or cash out', 'text' => 'Switch between the two lists. Provider, Shop Account and Payment Status narrow it, and Clear removes the filters.'],
                ['label' => 'Net Service Fees', 'text' => 'What you earned in fees on the transfers shown. By provider splits the amounts by JazzCash, Easypaisa and so on.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later. Mark as Paid settles the whole amount.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that transfer.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the transfer. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'New Wallet Load', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
