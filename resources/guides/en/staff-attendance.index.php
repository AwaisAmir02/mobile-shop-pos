<?php

// Guide: Staff Attendance. Labels match the screen exactly.

return [
    'title' => 'Staff Attendance',
    'summary' => 'Who came in and when. Staff clock in and out with their fingerprint, their QR badge, or a tap here; the first time of the day is in and the last is out.',
    'sections' => [
        [
            'id' => 'clock',
            'heading' => 'Clocking in and out',
            'intro' => 'Three ways, all landing in the same list.',
            'items' => [
                [
                    'label' => 'Clock in/out',
                    'text' => 'Records the time now for that person.',
                ],
                [
                    'label' => 'Scan a badge',
                    'text' => 'Point a scanner at the QR on a staff badge. Print one with Badge.',
                ],
                [
                    'label' => 'To device',
                    'text' => 'Gives the person a number on the fingerprint device. Enrol their finger on the device under that number.',
                ],
            ],
        ],
        [
            'id' => 'payroll',
            'heading' => 'With Payroll',
            'intro' => 'Days present in a month: any day with at least one clocking.',
            'items' => [
                [
                    'label' => 'Days present in',
                    'text' => 'A daily-wage payslip for that month starts with these days. You can still change them on the payslip.',
                ],
            ],
        ],
    ],
];
