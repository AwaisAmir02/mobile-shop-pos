<?php

// Guide: Vendors list. Labels match the screen exactly.

return [
    'title' => 'Vendors',
    'summary' => 'The suppliers you buy stock from, and what you still owe each one.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Search', 'text' => 'Find a vendor by name or phone number.'],
                ['label' => 'Owed', 'text' => 'What you still owe this vendor, mostly for stock saved as Unpaid or Partial on Stock In.'],
            ],
        ],
        [
            'id' => 'adding',
            'heading' => 'Adding a vendor',
            'items' => [
                ['label' => 'Add Vendor', 'text' => 'Only the Name is required. You can also add a vendor from Stock In with + New Vendor.'],
                ['label' => 'Company / Shop Name', 'text' => 'The business name, if it differs from the person\'s name.'],
                ['label' => 'Notes', 'text' => 'Anything to remember: payment terms, a second number.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'The ⋯ menu on each vendor',
            'items' => [
                ['label' => 'View', 'text' => 'The vendor\'s page, with everything bought from them.'],
                ['label' => 'Edit', 'text' => 'Change their details.'],
                ['label' => 'Delete', 'text' => 'Only for a vendor with no Stock In entries, so purchase records stay complete.'],
            ],
        ],
        [
            'id' => 'paying',
            'heading' => 'Paying a vendor',
            'intro' => 'Payments to a vendor are recorded against the stock they supplied, in Stock In History. The vendor\'s page has a button that takes you there.',
        ],
    ],
];
