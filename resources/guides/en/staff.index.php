<?php

// Guide: Staff. Labels match the screen exactly.

return [
    'title' => 'Staff',
    'summary' => 'The people who do the work. A bill line, a booking or a job card can say who did it, and commission is paid on that.',
    'sections' => [
        [
            'id' => 'staff',
            'heading' => 'Staff list',
            'intro' => 'No pay is shown here.',
            'items' => [
                [
                    'label' => 'Add Staff',
                    'text' => 'Name, role and phone.',
                ],
                [
                    'label' => 'Takes bookings',
                    'text' => 'Gives them a column on the Appointments calendar.',
                ],
            ],
        ],
    ],
];
