<?php

// Guide: Orders. Labels match the screen exactly.

return [
    'title' => 'Orders',
    'summary' => 'Orders booked for customers. Their stock is held, so it can\'t be sold twice, until they are delivered and become sales.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                [
                    'label' => 'Book Order',
                    'text' => 'A new order for a customer.',
                ],
                [
                    'label' => 'Load Sheets',
                    'text' => 'Put booked orders on a van for a day\'s run.',
                ],
                [
                    'label' => 'Status',
                    'text' => 'Booked, On a van, Delivered or Cancelled.',
                ],
            ],
        ],
    ],
];
