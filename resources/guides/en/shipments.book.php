<?php

// Guide: Book a Courier. Labels match the screen exactly.

return [
    'title' => 'Book a Courier',
    'summary' => 'Books this sale\'s parcel with your own courier account and gives you a tracking number and a label.',
    'sections' => [
        [
            'id' => 'details',
            'heading' => 'Filling it in',
            'items' => [
                ['label' => 'Deliver to', 'text' => 'Filled in from the WhatsApp or online store order, else from the customer. Check the address before booking.'],
                ['label' => 'City', 'text' => 'Pick it from the courier\'s own list; couriers only deliver to the cities they list.'],
                ['label' => 'Cash to collect (COD)', 'text' => 'Starts at what\'s still owed on the sale. Put 0 if the customer already paid.'],
                ['label' => 'Weight (grams)', 'text' => 'The courier charges by weight, so weigh the parcel.'],
                ['label' => 'Book parcel', 'text' => 'Books it with the courier straight away and opens Shipments.'],
            ],
        ],
    ],
];
