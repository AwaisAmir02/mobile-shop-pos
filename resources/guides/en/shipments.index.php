<?php

// Guide: Shipments. Labels match the screen exactly.

return [
    'title' => 'Shipments',
    'summary' => 'Every parcel you booked with your courier, and where it is now. Status updates from the courier every half hour.',
    'sections' => [
        [
            'id' => 'booking',
            'heading' => 'Sending a parcel',
            'steps' => [
                'Connect your own Leopards or PostEx account in Settings › Integrations › Couriers (once).',
                'Open the sale, WhatsApp order or online store order and press Book courier.',
                'Check the address, city, weight and the cash to collect, then press Book parcel.',
                'Press Print label and stick it on the parcel.',
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'On the way', 'text' => 'Parcels the courier hasn\'t delivered or returned yet.'],
                ['label' => 'Returns', 'text' => 'Parcels the customer didn\'t take, coming back or already back.'],
                ['label' => 'Refresh', 'text' => 'Asks the courier for the latest status now.'],
                ['label' => 'Cancel', 'text' => 'Only before the courier picks the parcel up.'],
                ['label' => 'Cash to Collect', 'text' => 'Cash on delivery still out with the couriers. The courier pays it to you directly; record it against the sale when it does.'],
            ],
        ],
        [
            'id' => 'returns',
            'heading' => 'When a parcel comes back',
            'intro' => 'A parcel the courier marks as returned shows Received back. Press it only once the parcel is in your hands. You can cancel the sale at the same time, which puts the stock back.',
        ],
    ],
];
