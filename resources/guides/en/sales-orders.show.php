<?php

// Guide: Order. Labels match the screen exactly.

return [
    'title' => 'Order',
    'summary' => 'One order. When it is delivered it becomes a sale for what was actually handed over.',
    'sections' => [
        [
            'id' => 'deliver',
            'heading' => 'Delivering',
            'items' => [
                [
                    'label' => 'Delivered',
                    'text' => 'Enter what was handed over and any money collected. The rest is on credit with their payment terms.',
                ],
                [
                    'label' => 'Delivery challan',
                    'text' => 'The challan that goes with the goods, for the customer to sign.',
                ],
                [
                    'label' => 'Not delivered',
                    'text' => 'Takes it off the van; it stays booked.',
                ],
                [
                    'label' => 'Cancel order',
                    'text' => 'Its stock is free again.',
                ],
            ],
        ],
    ],
];
