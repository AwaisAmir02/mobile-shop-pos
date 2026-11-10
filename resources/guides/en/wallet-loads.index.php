<?php

// Guide: Wallet Load (JazzCash, Easypaisa… cash in and cash out), and Wallet Load History (opens at 'history').

return [
    'title' => 'Wallet Load',
    'summary' => 'Mobile wallet transfers for customers. Cash In: the customer gives you cash and you send it from your account to theirs. Cash Out: they send money to your account and you hand them cash. You always send or hand over the full amount; anything the customer hasn\'t paid yet is their due.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Recording a transfer',
            'steps' => [
                'At the top, pick Cash In or Cash Out. Each box shows what moves: cash in and account out, or the other way round.',
                'Choose your account: Send From (your account) for Cash In, Customer Sends To (your account) for Cash Out.',
                'For Cash In, enter the Account Name and the Account / Mobile Number of the customer\'s wallet.',
                'Enter the Amount and check the Service Charge.',
                'Under Payment, enter what the customer has paid you so far, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => 'Service Charge', 'text' => 'Your fee, on both Cash In and Cash Out. It is suggested from the commission % in Settings, and you can change it.'],
                ['label' => 'Fee is already included in the amount above', 'text' => 'Tick it when the customer\'s amount already includes your fee. The line below it shows an example of what moves and what you keep.'],
                ['label' => 'Cash Received from Customer', 'text' => 'For Cash In: how much cash the customer handed you. The status (Paid, Partial or Unpaid) follows from it.'],
                ['label' => 'Received in Your Account', 'text' => 'For Cash Out: how much the customer has sent to your account so far.'],
                ['label' => 'Still Owed by Customer', 'text' => 'Shows when the customer has paid less than the total, on either side. It is their due, and appears on the Udhaar screen.'],
                ['label' => 'What moves, once fully paid', 'text' => 'How the load changes your cash in hand and your account, as Cash & Balances will show it.'],
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
                ['label' => 'Total Owed by Customers', 'text' => 'What customers still owe on the transfers in this list. Not in dues (older entry) marks a Cash Out saved before shortfalls counted as dues.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later: cash for Cash In, into the load\'s account for Cash Out. Mark as Paid settles the whole amount.'],
                ['label' => 'Download', 'text' => 'The PDF receipt for that transfer.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amounts.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the transfer. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'New Wallet Load', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
