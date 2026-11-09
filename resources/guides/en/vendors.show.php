<?php

// Guide: one vendor's page. Labels match the screen exactly.

return [
    'title' => 'Vendor page',
    'summary' => 'One supplier: what you owe them, what you have bought, and each delivery.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'The figures at the top',
            'items' => [
                ['label' => 'Total Owed to Vendor', 'text' => 'What you still owe for stock received from them.'],
                ['label' => 'Total Purchase Cost', 'text' => 'Everything bought from them, at cost price.'],
                ['label' => 'Stock Entries', 'text' => 'How many Stock In lines came from this vendor.'],
                ['label' => 'Total Units Purchased', 'text' => 'Items counted in pieces.'],
                ['label' => 'Weight, Volume & Length', 'text' => 'Items bought by kg, litre or metre, shown separately.', 'feature' => 'measured_units'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Stock Entries',
            'items' => [
                ['label' => 'Payment', 'text' => 'Paid, Partial or Unpaid for each delivery. Owed is what is left to pay on it.'],
                ['label' => 'Record payments in Stock In', 'text' => 'Opens Stock In History filtered to this vendor, where you record what you paid them.', 'needs' => 'stock-ins'],
            ],
        ],
    ],
];
