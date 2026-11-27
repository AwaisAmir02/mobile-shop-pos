<?php

// Guide (Roman Urdu): Appointments. Labels match the screen exactly.

return [
    'title' => 'Appointments',
    'summary' => 'Din ki bookings har staff ke column mein, aur walk-in queue.',
    'sections' => [
        [
            'id' => 'book',
            'heading' => 'Booking',
            'intro' => 'Customer, staff, waqt aur services chunein.',
            'items' => [
                [
                    'label' => 'New Booking',
                    'text' => 'Usi staff ki booking se takrati booking nahin hoti, siwaye class ke jis mein jagah ho.',
                ],
                [
                    'label' => 'Walk-in',
                    'text' => 'Din ka agla queue number milta hai.',
                ],
                [
                    'label' => 'Deposit',
                    'text' => 'Pehle li gayi raqam, bill par lag jati hai.',
                ],
            ],
        ],
        [
            'id' => 'bill',
            'heading' => 'Bill',
            'intro' => 'Bill dabane se New Sale booking ki services ke saath khulta hai.',
            'items' => [
                [
                    'label' => 'Remind',
                    'text' => 'Aap ke phone par WhatsApp reminder likha hua khulta hai.',
                ],
            ],
        ],
    ],
];
