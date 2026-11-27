<?php

// Guide: Appointments. Labels match the screen exactly.

return [
    'title' => 'Appointments',
    'summary' => 'The day\'s bookings in a column per staff member, and the walk-in queue.',
    'sections' => [
        [
            'id' => 'book',
            'heading' => 'Booking',
            'intro' => 'Choose the customer, staff, time and services.',
            'items' => [
                [
                    'label' => 'New Booking',
                    'text' => 'A booking that overlaps the same staff member\'s is refused, except a class with places left.',
                ],
                [
                    'label' => 'Walk-in',
                    'text' => 'Gets the day\'s next queue number.',
                ],
                [
                    'label' => 'Deposit',
                    'text' => 'Money taken ahead, used on the bill.',
                ],
            ],
        ],
        [
            'id' => 'bill',
            'heading' => 'Billing',
            'intro' => 'Bill opens New Sale with the booking\'s services.',
            'items' => [
                [
                    'label' => 'Remind',
                    'text' => 'Opens WhatsApp on your phone with a reminder typed in.',
                ],
            ],
        ],
    ],
];
