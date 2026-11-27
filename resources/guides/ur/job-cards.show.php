<?php

// Guide (Roman Urdu): Job Card. Labels match the screen exactly.

return [
    'title' => 'Job Card',
    'summary' => 'Ek job: halat, parts aur mazdoori, customer ki manzoori, advance, aur bill.',
    'sections' => [
        [
            'id' => 'estimate',
            'heading' => 'Parts aur mazdoori',
            'intro' => 'Bill hone par parts stock se nikalte hain.',
            'items' => [
                [
                    'label' => 'Customer approved',
                    'text' => 'Estimate par customer ki manzoori darj karta hai.',
                ],
                [
                    'label' => 'Ready',
                    'text' => 'Customer ko WhatsApp par batata hai, ya aap ko button deta hai.',
                ],
                [
                    'label' => 'Bill & deliver',
                    'text' => 'New Sale parts aur mazdoori ke saath khulta hai; warranty tab shuru hoti hai.',
                ],
            ],
        ],
    ],
];
