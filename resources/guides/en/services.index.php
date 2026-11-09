<?php

// Guide: Services (your own list of services), and Service History (opens at 'history').

return [
    'title' => 'Services',
    'summary' => 'Record any other service you offer, from your own list: photocopies, printing, phone setup and so on.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Recording a service',
            'steps' => [
                'Pick the Category, then the Item.',
                'Pick the Customer, or leave it on Walk-in.',
                'Enter the Amount you charged.',
                'Set the Payment Status, then press Save.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'On the form',
            'items' => [
                ['label' => '+ New Item', 'text' => 'Adds a service to the list without leaving the form. + New Category does the same for categories. Settings has the full list.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial or Unpaid: has the customer paid you? For Partial, enter the Amount Paid.'],
                ['label' => 'Received Into', 'text' => 'Cash, or the account the money went into, for your ledger.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'After saving',
            'items' => [
                ['label' => 'Log Another', 'text' => 'An empty form for the next one.'],
                ['label' => 'Service History', 'text' => 'The link at the top: every service you have recorded.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Service History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Show the services from a day or a range. Category, Item and Payment Status narrow it, and Clear removes the filters.'],
                ['label' => 'Total Collected', 'text' => 'What customers paid on the services shown. Total Owed by Customers is what is still unpaid.'],
                ['label' => 'Add Payment', 'text' => 'In the ⋯ menu: record money that came in later. Mark as Paid settles the whole amount.'],
                ['label' => 'Edit', 'text' => 'Change the details or the amount.', 'needs' => 'services-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels it. It stays on the list, marked Cancelled. It can\'t be undone.', 'needs' => 'services-edit-cancel'],
                ['label' => 'New Service', 'text' => 'Back to the form.'],
            ],
        ],
    ],
];
