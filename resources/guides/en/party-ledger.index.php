<?php

// Guide: Party Ledger (a customer's or vendor's statement). Labels match the screen exactly.

return [
    'title' => 'Party Ledger',
    'summary' => 'A statement for one customer or vendor, for any dates: every entry with a running balance. Good for settling up or sending them a copy.',
    'sections' => [
        [
            'id' => 'choosing',
            'heading' => 'Getting a statement',
            'steps' => [
                'Under Party type, pick Customers or Vendors.',
                'Choose the customer or vendor.',
                'Pick the dates: This month, This year, All time, or your own From and To.',
            ],
            'items' => [
                ['label' => 'Vendors', 'text' => 'Only for people allowed to see Vendors.', 'needs' => 'vendors'],
            ],
        ],
        [
            'id' => 'reading',
            'heading' => 'Reading the statement',
            'intro' => 'Each kind of money is kept as its own balance. They are never added together, so you can see exactly where an amount comes from.',
            'items' => [
                ['label' => 'Sales outstanding', 'text' => 'Customer: the unpaid part of their sales.'],
                ['label' => 'Udhaar balance', 'text' => 'Customer: udhaar given and repaid.'],
                ['label' => 'Owed to vendor', 'text' => 'Vendor: purchases and what you paid for them.'],
                ['label' => 'Opening & adjustments', 'text' => 'Old balances from before you used HisabOKitab, and corrections, added with Add Entry.'],
                ['label' => 'Balance brought forward', 'text' => 'The balance on the day before your From date. Closing balance is where it stands at the end.'],
                ['label' => 'Other amounts owed', 'text' => 'Customer: unpaid counter services, shown for reference only. Take those payments on the Udhaar screen.'],
                ['label' => 'Export', 'text' => 'Download the statement as a PDF or an Excel file, to keep or send.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Opening balances and adjustments',
            'items' => [
                ['label' => 'Add Entry', 'text' => 'The first entry is the Opening balance, what was owed before you started. After that, entries are Adjustments.'],
                ['label' => 'Direction', 'text' => 'Whether the entry makes them owe more or less (for a vendor: whether you owe more or less). The choices spell it out.'],
                ['label' => 'Note', 'text' => 'Required: say why the entry is being made.'],
                ['label' => 'Cancel', 'text' => 'On an opening or adjustment entry: it stays visible, marked Cancelled, but stops counting. It can\'t be undone.'],
            ],
        ],
    ],
];
