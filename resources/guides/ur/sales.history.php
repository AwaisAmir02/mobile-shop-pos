<?php

// Guide (Roman Urdu): Sales History. Labels stay in English, exactly as on screen.

return [
    'title' => 'Sales History',
    'summary' => 'Saari sales, nayi sab se upar, aur har sale par kitna baqi hai. Sale kholein, uski payment lein, ya list export karein.',
    'sections' => [
        [
            'id' => 'finding',
            'heading' => 'Sale dhoondna',
            'items' => [
                ['label' => 'Dates', 'text' => 'Sirf ek din ya kuch dinon ki sales dekhein: Today, Yesterday, Last 7 days, ya apni tareekhein.'],
                ['label' => 'Clear', 'text' => 'Wapas saari sales.'],
                ['label' => 'Export', 'text' => 'Chuni hui tareekhon ki list PDF ya Excel file mein download karein.'],
                ['label' => 'Total Outstanding', 'text' => 'Saari sales par mila kar kitna paisa baqi hai. Tareekhein badalne se yeh nahin badalta.'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Invoice', 'text' => 'Invoice number par tap karein, receipt khul jayegi.'],
                ['label' => 'Status', 'text' => 'Paid, Partial (kuch baqi hai), Unpaid (abhi kuch nahin mila) ya Cancelled. Cancelled sales halki nazar aati hain aur kahin nahin gintin.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'Har sale ka ⋯ menu',
            'items' => [
                ['label' => 'Record Payment', 'text' => 'Sirf un sales par jin par paisa baqi ho. Amount Paid Now aur payment ka tareeqa likhein. Sale aur customer ka balance dono update ho jate hain.'],
                ['label' => 'Download', 'text' => 'Us sale ki PDF invoice.'],
                ['label' => 'Edit', 'text' => 'Sale ke items, quantity ya qeemat badlein.', 'needs' => 'sales-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir sale cancel karke stock wapas kar deta hai. Yeh wapas nahin hota.', 'needs' => 'sales-edit-cancel'],
            ],
        ],
    ],
];
