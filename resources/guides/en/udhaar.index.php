<?php

// Guide: Udhaar list. Labels match the screen exactly.

return [
    'title' => 'Udhaar',
    'summary' => 'What each customer owes you, in one list: udhaar loans plus unpaid sales and counter services.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Total Outstanding', 'text' => 'Everything owed to you by all customers together.'],
                ['label' => 'Balance', 'text' => 'Due: they owe you. Advance: they paid ahead. Settled: nothing either way.'],
                ['label' => 'Other Dues', 'text' => 'They also owe on unpaid sales or counter services, not only udhaar loans.'],
                ['label' => 'View History', 'text' => 'The customer\'s udhaar page, with every entry and every unpaid sale.'],
                ['label' => 'Export', 'text' => 'Download the list as a PDF or an Excel file.'],
            ],
        ],
        [
            'id' => 'add',
            'heading' => 'Recording udhaar',
            'intro' => 'Udhaar on a sale is recorded by the sale itself (Partial or Udhaar on the register). Use Add Transaction for money that moves outside a sale.',
            'items' => [
                ['label' => 'Add Transaction', 'text' => 'Pick the Customer and the Type, then the Amount and the Date.'],
                ['label' => 'Udhaar Given', 'text' => 'You gave the customer money or goods on credit. Their balance goes up.'],
                ['label' => 'Repayment', 'text' => 'The customer paid you back. Their balance goes down.'],
                ['label' => 'Cash or Account', 'text' => 'Where the money went in or out, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Note', 'text' => 'Optional. Anything to remember about it.'],
            ],
        ],
    ],
];
