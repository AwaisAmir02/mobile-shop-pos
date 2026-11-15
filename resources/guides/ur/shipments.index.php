<?php

// Guide (Roman Urdu): Shipments. Labels match the screen exactly.

return [
    'title' => 'Shipments',
    'summary' => 'Courier ke zariye bheje gaye har parcel ki list, aur woh abhi kahan hai. Status har aadhe ghante courier se update hota hai.',
    'sections' => [
        [
            'id' => 'booking',
            'heading' => 'Parcel bhejna',
            'steps' => [
                'Settings › Integrations › Couriers mein apna Leopards ya PostEx account jorein (sirf ek dafa).',
                'Sale, WhatsApp order ya online store order kholein aur Book courier dabayein.',
                'Address, city, weight aur wasool karne wali raqam check karein, phir Book parcel.',
                'Print label dabayein aur label parcel par lagayein.',
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'On the way', 'text' => 'Jo parcel abhi deliver ya wapas nahi hue.'],
                ['label' => 'Returns', 'text' => 'Jo parcel customer ne nahi liye, wapas aa rahe hain ya aa chuke.'],
                ['label' => 'Refresh', 'text' => 'Courier se abhi taaza status mangwata hai.'],
                ['label' => 'Cancel', 'text' => 'Sirf courier ke parcel uthane se pehle.'],
                ['label' => 'Cash to Collect', 'text' => 'Cash on delivery jo abhi courier ke paas hai. Courier yeh raqam seedha aap ko deta hai; jab de to sale par payment record karein.'],
            ],
        ],
        [
            'id' => 'returns',
            'heading' => 'Jab parcel wapas aaye',
            'intro' => 'Jo parcel courier wapas mark kare us par Received back aata hai. Yeh tab dabayein jab parcel aap ke haath mein ho. Saath hi sale cancel kar sakte hain, is se stock wapas aa jata hai.',
        ],
    ],
];
