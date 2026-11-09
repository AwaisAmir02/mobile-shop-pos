<?php

// Guide (Roman Urdu): Udhaar list. Labels stay in English, exactly as on screen.

return [
    'title' => 'Udhaar',
    'summary' => 'Har customer par aap ka kitna baqi hai, ek list mein: udhaar loans aur unpaid sales aur counter services.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Total Outstanding', 'text' => 'Saare customers par mila kar aap ka kitna baqi hai.'],
                ['label' => 'Balance', 'text' => 'Due: unhon ne dena hai. Advance: pehle se de rakha hai. Settled: koi len den baqi nahin.'],
                ['label' => 'Other Dues', 'text' => 'Sirf udhaar nahin, unpaid sales ya counter services ke paise bhi baqi hain.'],
                ['label' => 'View History', 'text' => 'Customer ka udhaar page, har entry aur har unpaid sale ke saath.'],
                ['label' => 'Export', 'text' => 'List PDF ya Excel file mein download karein.'],
            ],
        ],
        [
            'id' => 'add',
            'heading' => 'Udhaar darj karna',
            'intro' => 'Sale wala udhaar sale khud darj karti hai (register par Partial ya Udhaar). Add Transaction un paison ke liye hai jo sale ke bahar len den hue.',
            'items' => [
                ['label' => 'Add Transaction', 'text' => 'Customer aur Type chunein, phir Amount aur Date.'],
                ['label' => 'Udhaar Given', 'text' => 'Aap ne customer ko paise ya maal udhaar diya. Unka balance barh jata hai.'],
                ['label' => 'Repayment', 'text' => 'Customer ne paise wapas diye. Unka balance kam ho jata hai.'],
                ['label' => 'Cash or Account', 'text' => 'Paisa kahan aaya ya kahan se gaya, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Note', 'text' => 'Zaroori nahin. Yaad rakhne wali koi baat.'],
            ],
        ],
    ],
];
