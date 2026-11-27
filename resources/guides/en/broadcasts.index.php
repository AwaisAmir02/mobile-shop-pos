<?php

// Guide: Broadcasts. Labels match the screen exactly.

return [
    'title' => 'Broadcasts',
    'summary' => 'One message to many customers: a diet plan, new timings, an offer.',
    'sections' => [
        [
            'id' => 'send',
            'heading' => 'Sending',
            'intro' => 'WhatsApp only lets your number send free text to people who messaged you in the last 24 hours.',
            'items' => [
                [
                    'label' => 'Send on WhatsApp',
                    'text' => 'For everyone else: opens WhatsApp on your phone with the message typed in.',
                ],
            ],
        ],
    ],
];
