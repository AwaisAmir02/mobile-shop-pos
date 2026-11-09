<?php

// Guide: Stock In (record goods arriving). Labels match the screen exactly.

return [
    'title' => 'Stock In',
    'summary' => 'Record stock that arrives from a supplier. Put every product on the supplier\'s bill on one receipt, then save it once.',
    'sections' => [
        [
            'id' => 'quick-start',
            'heading' => 'Recording a delivery',
            'steps' => [
                'Pick the Product. Category and Sub-Category narrow the list if it\'s long.',
                'Enter the Quantity, the Cost Price and the Selling Price.',
                ['text' => 'Enter the Expiry Date if the product has one.', 'feature' => 'expiry_tracking'],
                'Press Add to Receipt. Repeat for each product with Add More.',
                'Fill in Vendor & Payment, then press Save Receipt. Stock goes up straight away.',
            ],
        ],
        [
            'id' => 'product',
            'heading' => 'Adding a product to the receipt',
            'items' => [
                ['label' => 'Product', 'text' => 'The list shows each product\'s current stock. + New Product adds one without leaving this screen.'],
                ['label' => 'Cost Price', 'text' => 'What you paid the supplier for one. Your profit is worked out from it.'],
                ['label' => 'Selling Price', 'text' => 'What this batch sells for on the register. The oldest stock sells first, at its own price.'],
                ['label' => 'Expiry Date', 'text' => 'The last day it can be sold. It fills in from the product\'s Shelf Life when that is set.', 'feature' => 'expiry_tracking'],
                ['label' => '×', 'text' => 'On a receipt line: takes it off the receipt before you save.'],
            ],
        ],
        [
            'id' => 'payment',
            'heading' => 'Vendor & Payment',
            'intro' => 'This part applies to every product on the receipt.',
            'items' => [
                ['label' => 'Vendor', 'text' => 'Who supplied the goods. + New Vendor adds one.', 'needs' => 'vendors'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid. Partial or Unpaid means you still owe the supplier. It shows on their vendor page.'],
                ['label' => 'Amount Paid', 'text' => 'What you actually paid. Typing an amount sets the status for you.'],
                ['label' => 'Paid From', 'text' => 'Cash or the account the money left from, for your ledger.', 'feature' => 'ledger'],
                ['label' => 'Date Received', 'text' => 'The day the goods arrived. It can\'t be in the future.'],
                ['label' => 'Note', 'text' => 'Anything to remember, for example the supplier\'s bill number.'],
                ['label' => 'Total Cost', 'text' => 'The whole receipt at cost price.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Stock In History', 'text' => 'The link at the top. Every saved receipt, to look up, edit or pay later. Its tabs also lead to Write-offs, for stock that was damaged, lost or expired.'],
                ['label' => 'Expiring & Expired', 'text' => 'Another tab there: stock that has expired or is about to.', 'feature' => 'expiry_tracking'],
            ],
        ],
    ],
];
