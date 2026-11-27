<?php

// Guide: Dues Aging report. Labels match the screen exactly.

return [
    'title' => 'Dues Aging',
    'summary' => 'Who owes the shop money and for how long: each customer\'s unpaid amount split into 0–30, 31–60, 61–90 and over 90 days. Use it to decide whom to chase first. Bills and udhaar loans are shown separately and never added together.',
    'sections' => [
        [
            'id' => 'totals',
            'heading' => 'The totals',
            'items' => [
                ['label' => 'Bills owed', 'text' => 'Everything unpaid on sales and services, the same amounts the Udhaar list counts.'],
                ['label' => 'Overdue bills', 'text' => 'The part of those bills past the due date set on the sale (from the customer\'s payment terms).'],
                ['label' => 'Udhaar owed', 'text' => 'Udhaar loans not yet repaid. Repayments pay off the oldest loans first.'],
                ['label' => 'Over 90 days', 'text' => 'Money owed for more than three months: the hardest to recover.'],
            ],
        ],
        [
            'id' => 'table',
            'heading' => 'The table',
            'intro' => 'Each customer who owes something, the most first. A bill is aged from its own date, so one customer can have money in several columns.',
            'items' => [
                ['label' => 'Show', 'text' => 'Switch between Bills and Udhaar loans.'],
                ['label' => 'Area / route', 'text' => 'Only the customers in one area, to plan a recovery round. Set the area on the customer.'],
                ['label' => 'Overdue only', 'text' => 'Only customers with a bill past its due date.'],
                ['label' => 'Export', 'text' => 'Download the list as PDF or Excel, with the filters you chose.'],
            ],
        ],
    ],
];
