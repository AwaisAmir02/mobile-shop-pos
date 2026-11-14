<?php

// Guide: Warranty Check. Labels match the screen exactly.

return [
    'title' => 'Warranty Check',
    'summary' => 'When a customer brings a phone back, find it here by its IMEI or the invoice number and see at once whether it is still under warranty.',
    'sections' => [
        [
            'id' => 'search',
            'heading' => 'Finding the phone',
            'steps' => [
                'Ask for the IMEI (dial *#06# on the phone, or read it from the box), or the invoice number on their receipt.',
                'Type it in the box. The full IMEI works, and so do its last few digits.',
                'Every sale that matches shows up underneath.',
            ],
        ],
        [
            'id' => 'result',
            'heading' => 'Reading the result',
            'items' => [
                ['label' => 'Under warranty', 'text' => 'Still covered. It shows how many days are left and the last day of cover.'],
                ['label' => 'Expired', 'text' => 'The warranty has ended. It shows the date it ended.'],
                ['label' => 'No warranty', 'text' => 'No warranty was set on the product when it was sold.'],
                ['label' => 'Sale cancelled', 'text' => 'That sale was cancelled, so it carries no warranty.'],
                ['label' => 'Invoice', 'text' => 'Opens the original sale.'],
            ],
        ],
        [
            'id' => 'setting',
            'heading' => 'Where the warranty comes from',
            'intro' => 'Set Warranty (months) and Warranty from (Brand or Shop) on the product, on the Products screen. Each unit keeps the warranty it was sold with, even if you change the product later.',
        ],
    ],
];
