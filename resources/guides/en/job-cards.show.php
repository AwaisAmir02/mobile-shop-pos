<?php

// Guide: Job Card. Labels match the screen exactly.

return [
    'title' => 'Job Card',
    'summary' => 'One job: its status, parts and labour, the customer\'s OK, advance, and billing.',
    'sections' => [
        [
            'id' => 'estimate',
            'heading' => 'Parts & labour',
            'intro' => 'Parts come from stock when the job is billed.',
            'items' => [
                [
                    'label' => 'Customer approved',
                    'text' => 'Records the customer\'s OK on the estimate.',
                ],
                [
                    'label' => 'Ready',
                    'text' => 'Tells the customer on WhatsApp, or gives you a button to.',
                ],
                [
                    'label' => 'Bill & deliver',
                    'text' => 'Opens New Sale with the parts and labour; the warranty starts then.',
                ],
            ],
        ],
    ],
];
