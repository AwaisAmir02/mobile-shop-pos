<?php

// Guide: Book Order. Labels match the screen exactly.

return [
    'title' => 'Book Order',
    'summary' => 'Book what a customer wants delivered. Prices start at their price level and can be changed; you can\'t book more than is free in stock.',
    'sections' => [
        [
            'id' => 'items',
            'heading' => 'Items',
            'intro' => 'Each line shows how much is free to book. A running scheme is added on delivery.',
            'items' => [
                [
                    'label' => 'Add a product',
                    'text' => 'Search and add it to the order.',
                ],
                [
                    'label' => 'Qty',
                    'text' => 'In pieces, or in cartons when the line says so.',
                ],
                [
                    'label' => 'Price each',
                    'text' => 'Starts at the customer\'s price; change it if agreed.',
                ],
            ],
        ],
        [
            'id' => 'order',
            'heading' => 'Order',
            'items' => [
                [
                    'label' => 'Booked by',
                    'text' => 'The booker or salesman who took the order.',
                ],
                [
                    'label' => 'Book Order',
                    'text' => 'Saves it and holds its stock. Over the credit limit only warns here.',
                ],
            ],
        ],
    ],
];
