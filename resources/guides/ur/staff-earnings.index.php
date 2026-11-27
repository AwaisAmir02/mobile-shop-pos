<?php

// Guide (Roman Urdu): Staff Earnings. Labels match the screen exactly.

return [
    'title' => 'Staff Earnings',
    'summary' => 'Har shakhs ke kaam par commission, discount aur wapsi ke baad. Payroll bhi yahi raqam leta hai.',
    'sections' => [
        [
            'id' => 'rules',
            'heading' => 'Commission ke rules',
            'intro' => 'Ek shakhs ka rule sab ke rule se upar hai; sab se khaas rule chalta hai.',
            'items' => [
                [
                    'label' => 'Pays on',
                    'text' => 'Ek service, category, sab services, membership, ya bechay gaye products.',
                ],
                [
                    'label' => 'Kind',
                    'text' => 'Line ka %, ya har kaam par mukarrar Rs.',
                ],
            ],
        ],
        [
            'id' => 'payroll',
            'heading' => 'Payroll ke saath',
            'intro' => 'Payslip banane par Commission ki line lagti hai.',
            'items' => [
                [
                    'label' => 'Later changes',
                    'text' => 'Mahina ada hone ke baad wapsi ya cancel bill agli payslip mein adjustment ban kar aata hai.',
                ],
            ],
        ],
    ],
];
