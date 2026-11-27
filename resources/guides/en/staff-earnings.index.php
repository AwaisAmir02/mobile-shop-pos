<?php

// Guide: Staff Earnings. Labels match the screen exactly.

return [
    'title' => 'Staff Earnings',
    'summary' => 'Commission on what each person did, after discounts and returns. Payroll uses the same figure.',
    'sections' => [
        [
            'id' => 'rules',
            'heading' => 'Commission rules',
            'intro' => 'A rule for one person beats one for everyone; the most exact one wins.',
            'items' => [
                [
                    'label' => 'Pays on',
                    'text' => 'One service, a category, all services, a membership, or products sold.',
                ],
                [
                    'label' => 'Kind',
                    'text' => '% of the line, or a fixed Rs for each one done.',
                ],
            ],
        ],
        [
            'id' => 'payroll',
            'heading' => 'With Payroll',
            'intro' => 'Generating a payslip adds a Commission line.',
            'items' => [
                [
                    'label' => 'Later changes',
                    'text' => 'A return or cancelled bill after the month was paid comes on the next payslip as an adjustment.',
                ],
            ],
        ],
    ],
];
