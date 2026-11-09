<?php

// Guide (Roman Urdu): Expenses. Labels stay in English, exactly as on screen.

return [
    'title' => 'Expenses',
    'summary' => 'Dukaan ke kharche: kiraya, bijli, chai, aane jaane ka kharcha. Yeh Dashboard aur reports mein aap ki kamai se minus hote hain.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Log an Expense',
            'steps' => [
                'Description likhein, jaise "Dukaan ka bijli ka bill".',
                'Amount aur Date likhein.',
                'Save dabayein. Kharcha neeche list mein aa jata hai.',
            ],
            'items' => [
                ['label' => 'Paid From', 'text' => 'Paisa cash se gaya ya kis account se, aap ke ledger ke liye.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ke kharche dekhein. Clear se wapas saare.'],
                ['label' => 'Total Expenses', 'text' => 'Dikhaye gaye kharchon ka total.'],
                ['label' => 'Status', 'text' => 'Active, ya Cancelled. Cancelled kharcha list mein rehta hai lekin ginti mein nahin aata.'],
                ['label' => 'From Payroll', 'text' => 'Payroll se di gayi tankhwahein yahan khud aa jati hain. Unhein Payroll mein badlein, yahan nahin. Advance written off bhi isi tarah hai.', 'feature' => 'payroll'],
                ['label' => 'Edit', 'text' => '⋯ menu mein: description, raqam ya tareekh theek karein.', 'needs' => 'expenses-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir kharcha cancel kar deta hai. Yeh wapas nahin hota.', 'needs' => 'expenses-edit-cancel'],
            ],
        ],
    ],
];
