<?php

// Guide: Cheques. Labels match the screen exactly.

return [
    'title' => 'Cheques',
    'summary' => 'Post-dated cheques you hold from customers and have given to vendors. A cheque pays nothing until it clears; if it bounces after clearing, what it paid is owed again.',
    'sections' => [
        [
            'id' => 'record',
            'heading' => 'Recording a cheque',
            'intro' => 'Record it the day you get it (or give it), with the date written on it.',
            'items' => [
                [
                    'label' => 'Record Cheque',
                    'text' => 'Customer or vendor, cheque number, bank, amount and the cheque date.',
                ],
                [
                    'label' => 'Received / Issued to vendors',
                    'text' => 'Switch between cheques you hold and cheques you gave out.',
                ],
            ],
        ],
        [
            'id' => 'actions',
            'heading' => 'When the bank acts',
            'intro' => 'Use the row menu on each cheque.',
            'items' => [
                [
                    'label' => 'Deposited',
                    'text' => 'You took it to the bank; choose the account.',
                ],
                [
                    'label' => 'Cleared',
                    'text' => 'The money arrived. A customer\'s cheque pays their udhaar, then their oldest bills; a vendor\'s pays their oldest stock.',
                ],
                [
                    'label' => 'Bounced',
                    'text' => 'It came back unpaid. If it had cleared, what it paid is owed again and the money leaves the account.',
                ],
                [
                    'label' => 'Cancel',
                    'text' => 'For a cheque recorded by mistake or handed back before banking.',
                ],
            ],
        ],
    ],
];
