<?php

// Guide: Sales History. Labels match the screen exactly.

return [
    'title' => 'Sales History',
    'summary' => 'Every sale, newest first, with what each one still owes. Open a sale, take a payment against it, or export the list.',
    'sections' => [
        [
            'id' => 'finding',
            'heading' => 'Finding a sale',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show only the sales from a day or a range: Today, Yesterday, Last 7 days, or your own dates.'],
                ['label' => 'Clear', 'text' => 'Back to every sale.'],
                ['label' => 'Export', 'text' => 'Download the list as a PDF or an Excel file, for the dates you picked.'],
                ['label' => 'Total Outstanding', 'text' => 'Money still owed on all sales together. It doesn\'t change with the dates.'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Invoice', 'text' => 'Tap the invoice number to open the receipt.'],
                ['label' => 'Status', 'text' => 'Paid, Partial (some still due), Unpaid (nothing paid yet) or Cancelled. Cancelled sales are faded and don\'t count anywhere.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'The ⋯ menu on each sale',
            'items' => [
                ['label' => 'Record Payment', 'text' => 'Only on sales with money still due. Enter Amount Paid Now and how it was paid. The sale and the customer\'s balance update.'],
                ['label' => 'Download', 'text' => 'The PDF invoice for that sale.'],
                ['label' => 'Edit', 'text' => 'Change the sale\'s items, quantities or prices.', 'needs' => 'sales-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the sale and puts the stock back. It can\'t be undone.', 'needs' => 'sales-edit-cancel'],
            ],
        ],
    ],
];
