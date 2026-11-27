<?php

// Guide: New Job Card. Labels match the screen exactly.

return [
    'title' => 'New Job Card',
    'summary' => 'Open a job when a unit comes in.',
    'sections' => [
        [
            'id' => 'job',
            'heading' => 'The job',
            'intro' => 'Customer, technician and promised date.',
            'items' => [
                [
                    'label' => 'Next service in (days)',
                    'text' => 'Next service in (days): when the job is delivered we work out the due date and remind the customer on WhatsApp.',
                ],
                [
                    'label' => 'Site visit',
                    'text' => 'For work at the customer\'s place, with an address and a visit charge.',
                ],
                [
                    'label' => 'Brought in',
                    'text' => 'Each unit with the details its kind asks for.',
                ],
            ],
        ],
    ],
];
