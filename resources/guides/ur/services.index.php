<?php

// Guide (Roman Urdu): Services and Service History. Labels stay in English, exactly as on screen.

return [
    'title' => 'Services',
    'summary' => 'Apni list se koi bhi aur service darj karein: photocopy, printing, phone setup waghera.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Service kaise darj karein',
            'steps' => [
                'Category chunein, phir Item.',
                'Customer chunein, ya Walk-in rehne dein.',
                'Jitne paise liye woh Amount likhein.',
                'Payment Status set karein, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => '+ New Item', 'text' => 'Form chhore baghair list mein nayi service shamil karta hai. + New Category categories ke liye yahi karta hai. Poori list Settings mein hai.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid: customer ne aap ko paise diye? Partial ho to Amount Paid likhein.'],
                ['label' => 'Received Into', 'text' => 'Paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Log Another', 'text' => 'Agli service ke liye khaali form.'],
                ['label' => 'Service History', 'text' => 'Upar wala link: aap ki darj ki hui saari services.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Service History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ki services dekhein. Category, Item aur Payment Status se chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Collected', 'text' => 'Dikhayi gayi services par customers ne kitna diya. Total Owed by Customers jo abhi baqi hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: baad mein aane wala paisa darj karein. Mark as Paid poori raqam chukta kar deta hai.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'services-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir cancel kar deta hai. List mein Cancelled likh kar rehti hai. Yeh wapas nahin hota.', 'needs' => 'services-edit-cancel'],
                ['label' => 'New Service', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
