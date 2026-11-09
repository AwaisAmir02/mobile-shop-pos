<?php

// Guide (Roman Urdu): one customer's Udhaar History. Labels stay in English, exactly as on screen.

return [
    'title' => 'Udhaar History',
    'summary' => 'Ek customer par aap ka saara baqaya: udhaar ki har entry, aur unki unpaid sales aur services.',
    'sections' => [
        [
            'id' => 'figures',
            'heading' => 'Upar wali figures',
            'items' => [
                ['label' => 'Current Balance', 'text' => 'Abhi unhon ne kul kitna dena hai, ya pehle se kitna de rakha hai.'],
                ['label' => 'Udhaar Loans', 'text' => 'Udhaar wala hissa, neeche wali table se.'],
                ['label' => 'Unpaid Sales & Services', 'text' => 'Unki sales aur counter services ka baqi hissa, neeche likha hai.'],
            ],
        ],
        [
            'id' => 'loans',
            'heading' => 'Udhaar loans',
            'items' => [
                ['label' => 'Add Transaction', 'text' => 'Udhaar dein to Udhaar Given, paise wapas milein to Repayment.'],
                ['label' => 'Running Balance', 'text' => 'Har entry ke baad unka udhaar balance.'],
                ['label' => 'Entered By', 'text' => 'Entry kis ne daali.'],
            ],
        ],
        [
            'id' => 'fixing',
            'heading' => 'Ghalat entry theek karna (⋯ menu)',
            'items' => [
                ['label' => 'Edit', 'text' => 'Entry ki type, raqam, tareekh ya note badlein.'],
                ['label' => 'Reverse', 'text' => 'Aaj ki tareekh mein ulti entry daal deta hai, taake ghalti aur uski durusti dono history mein rahein. Kuch badalta ya mit-ta nahin.'],
                ['label' => 'Cancel', 'text' => 'Entry par Cancelled lag jata hai. Balance mein nahin ginti lekin record ke liye nazar aati rehti hai. Yeh wapas nahin hota.'],
            ],
        ],
        [
            'id' => 'other',
            'heading' => 'Other Amounts Owed',
            'items' => [
                ['label' => 'Source', 'text' => 'Paise kis sale ya counter service ke baqi hain.'],
                ['label' => 'Record Payment', 'text' => 'Us sale ya service ki payment lein: Amount Paid Now aur payment ka tareeqa. Balance foran update ho jata hai.'],
            ],
        ],
    ],
];
