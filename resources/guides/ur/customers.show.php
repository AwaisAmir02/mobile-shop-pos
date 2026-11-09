<?php

// Guide (Roman Urdu): one customer's page. Labels stay in English, exactly as on screen.

return [
    'title' => 'Customer page',
    'summary' => 'Ek customer: total kitna baqi hai, kahan se baqi hai, aur unki saari sales.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'Upar wali figures',
            'items' => [
                ['label' => 'Total Owed', 'text' => 'Unhon ne kul kitna dena hai: udhaar loans aur unpaid sales aur services mila kar. Wahi raqam jo Udhaar list par hai.'],
                ['label' => 'Sales Outstanding', 'text' => 'Unki sales ka baqi hissa.'],
                ['label' => 'Udhaar Loans', 'text' => 'Udhaar ke taur par diye ya liye gaye paise, sales se alag.'],
                ['label' => 'Total Sales', 'text' => 'Unhon ne kul kitna khareeda, aur kitni sales mein.'],
            ],
        ],
        [
            'id' => 'below',
            'heading' => 'Neeche',
            'items' => [
                ['label' => 'Sales History', 'text' => 'Is customer ki har sale. Kisi par tap karein, receipt khul jayegi.'],
                ['label' => 'Open their Udhaar', 'text' => 'Unka udhaar page: udhaar ya wapsi darj karein, ya kisi unpaid sale ki payment lein.', 'needs' => 'udhaar'],
            ],
        ],
    ],
];
