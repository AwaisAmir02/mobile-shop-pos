<?php

// Guide (Roman Urdu): Book a Courier. Labels match the screen exactly.

return [
    'title' => 'Book a Courier',
    'summary' => 'Is sale ka parcel aap ke apne courier account se book karta hai aur tracking number aur label deta hai.',
    'sections' => [
        [
            'id' => 'details',
            'heading' => 'Bharna',
            'items' => [
                ['label' => 'Deliver to', 'text' => 'WhatsApp ya online store order se bhara jata hai, warna customer se. Book karne se pehle address check karein.'],
                ['label' => 'City', 'text' => 'Courier ki apni list se chunein; courier sirf unhi cities mein deliver karta hai.'],
                ['label' => 'Cash to collect (COD)', 'text' => 'Sale par jitna baaki hai wahi se shuru hota hai. Agar customer pehle de chuka hai to 0 likhein.'],
                ['label' => 'Weight (grams)', 'text' => 'Courier weight ke hisaab se charge karta hai, is liye parcel tolein.'],
                ['label' => 'Book parcel', 'text' => 'Foran courier ke saath book karta hai aur Shipments kholta hai.'],
            ],
        ],
    ],
];
