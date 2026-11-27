<?php

// Guide: Fingerprint Devices. Labels match the screen exactly.

return [
    'title' => 'Fingerprint Devices',
    'summary' => 'ZKTeco-type fingerprint machines that send check-ins here over the internet (Cloud Server / ADMS).',
    'sections' => [
        [
            'id' => 'setup',
            'heading' => 'Setting up',
            'intro' => 'Add the device\'s serial number, then set its Cloud Server address.',
            'items' => [
                [
                    'label' => 'Take expired members off',
                    'text' => 'Each night members whose membership ended are removed from the device, and put back when they renew.',
                ],
            ],
        ],
    ],
];
