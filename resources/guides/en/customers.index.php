<?php

// Guide: Customers list. Labels match the screen exactly.

return [
    'title' => 'Customers',
    'summary' => 'Your customers, with what each one owes. Add them here, or straight from the sales register while making a sale.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Search', 'text' => 'Find a customer by name or phone number.'],
                ['label' => 'Balance', 'text' => 'Due: they owe you this much, from udhaar, unpaid sales and services together. Advance: they have paid you ahead. Settled: nothing either way.'],
            ],
        ],
        [
            'id' => 'adding',
            'heading' => 'Adding a customer',
            'items' => [
                ['label' => 'Add Customer', 'text' => 'Only the Name is required.'],
                ['label' => 'Phone', 'text' => 'Worth adding: it helps find the customer, and tell apart two people with the same name.'],
                ['label' => 'Address / Notes', 'text' => 'Where they live, or anything to remember about them.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'The ⋯ menu on each customer',
            'items' => [
                ['label' => 'View', 'text' => 'The customer\'s page: their sales, what they owe and their udhaar.'],
                ['label' => 'Edit', 'text' => 'Change the name, phone or address.'],
                ['label' => 'Delete', 'text' => 'Only for a customer with no history at all: no sales, udhaar or counter services. Anyone else stays, so the records stay complete.'],
            ],
        ],
    ],
];
