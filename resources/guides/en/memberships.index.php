<?php

// Guide: Members. Labels match the screen exactly.

return [
    'title' => 'Members',
    'summary' => 'Who holds a membership or package, where they stand today and what they owe.',
    'sections' => [
        [
            'id' => 'members',
            'heading' => 'Members',
            'intro' => 'Filter by standing.',
            'items' => [
                [
                    'label' => 'Renew',
                    'text' => 'Opens New Sale with the same membership; it carries on from the day the current one ends.',
                ],
                [
                    'label' => 'Freeze',
                    'text' => 'Pauses a membership; its end moves out by the same days.',
                ],
                [
                    'label' => 'To device',
                    'text' => 'Gives the member a number and sends them to the fingerprint device.',
                ],
            ],
        ],
    ],
];
