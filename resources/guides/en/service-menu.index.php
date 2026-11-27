<?php

// Guide: Service Menu. Labels match the screen exactly.

return [
    'title' => 'Service Menu',
    'summary' => 'Your services with prices and times, and the memberships and packages you sell. Everything here is sold as a line on the bill at New Sale, next to products.',
    'sections' => [
        [
            'id' => 'services',
            'heading' => 'Services',
            'intro' => 'Each service has a price and how long it takes.',
            'items' => [
                [
                    'label' => 'New Service',
                    'text' => 'Name, category, price and time. The time sizes a booking on the calendar.',
                ],
                [
                    'label' => 'Places',
                    'text' => 'More than 1 makes it a class: that many people can book the same time.',
                ],
            ],
        ],
        [
            'id' => 'memberships',
            'heading' => 'Memberships & packages',
            'intro' => 'A membership runs for a time; a package is a number of sessions.',
            'items' => [
                [
                    'label' => 'Membership (by time)',
                    'text' => 'Lasts so many days or months, and can be frozen up to the days you allow.',
                ],
                [
                    'label' => 'Package (by sessions)',
                    'text' => 'A number of sessions, optionally to be used within a time, on the services you tick.',
                ],
            ],
        ],
    ],
];
