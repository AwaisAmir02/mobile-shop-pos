<?php

// Guide: Promotions. Labels match the screen exactly.

return [
    'title' => 'Promotions',
    'summary' => 'Money off for a while: a percentage or a fixed amount off each piece, between two dates, on chosen products, variant groups, categories or everything. New Sale takes it off by itself, and the receipt shows it on the line. WhatsApp and online store orders don\'t get promotions.',
    'sections' => [
        [
            'id' => 'adding',
            'heading' => 'Adding a promotion',
            'items' => [
                ['label' => 'Name', 'text' => 'Printed on the receipt next to the line it took money off, e.g. Eid Sale.'],
                ['label' => 'Discount', 'text' => '% off takes that share off the line. Rs off each takes that amount off every piece (or every kg for goods sold by weight), never more than the line comes to.'],
                ['label' => 'Applies to', 'text' => 'Everything, or just the products, variant groups or categories you pick. A variant group covers every size and colour in it.'],
                ['label' => 'Starts / Ends', 'text' => 'It runs from the start date to the end date, both included. Leave Ends blank to run it until you pause it. A sale entered for an earlier date gets the promotions that ran on that date.'],
            ],
        ],
        [
            'id' => 'register',
            'heading' => 'At the register',
            'intro' => 'Each line shows the promotion it got and how much it took off. The cashier can\'t remove it. If two promotions cover a line, it gets the one that takes off more. A discount the cashier types on the line comes off first, and the promotion is worked out on what is left. Editing a sale keeps the promotion it was sold with and doesn\'t add new ones.',
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'Status', 'text' => 'Running, Starts (a date still to come), Ended, or Paused.'],
                ['label' => 'Given so far', 'text' => 'How much it has taken off, on how many sales. Cancelled sales don\'t count.'],
                ['label' => 'Pause', 'text' => 'Stops it for new sales straight away. Turn back on to restart it.'],
                ['label' => 'Edit', 'text' => 'Once a sale has used a promotion, only its name and dates can change, so old sales and their edits stay right. To change the discount, end it and add a new one.'],
                ['label' => 'Delete', 'text' => 'Only for a promotion no sale has used yet.'],
            ],
        ],
    ],
];
