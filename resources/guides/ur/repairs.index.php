<?php

// Guide (Roman Urdu): Repairs and Repair History. Labels stay in English, exactly as on screen.

return [
    'title' => 'Repairs',
    'summary' => 'Repair ka kaam darj karein: kya theek kiya, kitne paise lagaye aur customer ne kitne diye. Repair History mein har kaam aur baqi raqam nazar aati hai.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Repair kaise darj karein',
            'steps' => [
                'Main Category chunein, chahein to Sub-Category bhi.',
                'Description mein mukhtasar likhein, jaise "iPhone 13, screen tooti hui".',
                'Customer chunein, ya Walk-in rehne dein.',
                'Service Charges (aap ki mehnat) aur Products Charges (lagaya gaya part) likhein. Discount diya ho to woh bhi.',
                'Payment Status mein batayein customer ne paise diye ya nahin, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => 'Date', 'text' => 'Aaj ke liye khaali chhor dein, ya pichhla din chunein.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid. Jo nahin mila woh customer par baqi dikhta hai.'],
                ['label' => 'Amount Paid', 'text' => 'Customer ne asal mein kitne diye.'],
                ['label' => 'Received Into', 'text' => 'Paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Total', 'text' => 'Service Charges aur Products Charges, Discount minus kar ke.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'PDF receipt, print karne ya customer ko bhejne ke liye.'],
                ['label' => 'Log Another', 'text' => 'Agle kaam ke liye khaali form.'],
                ['label' => 'Repair History', 'text' => 'Upar wala link: aap ke darj kiye saare repairs.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Repair History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ke repairs dekhein. Category aur Payment Status se aur chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Collected', 'text' => 'Dikhaye gaye repairs par customers ne kitna diya. Baqi totals bhi filters ke saath badalte hain: kitna baqi hai, service aur parts ke charges.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: customer ne baqi raqam ka kuch ya saara hissa diya. Amount Paid Now likhein.'],
                ['label' => 'Mark as Paid', 'text' => 'Customer ne saari baqi raqam de di.'],
                ['label' => 'Download', 'text' => 'Us repair ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya charges badlein.', 'needs' => 'repairs-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir repair cancel kar deta hai. List mein Cancelled likh kar rehta hai aur totals mein nahin ginta. Yeh wapas nahin hota.', 'needs' => 'repairs-edit-cancel'],
                ['label' => 'New Repair', 'text' => 'Naya kaam darj karne ke liye wapas form par.'],
            ],
        ],
    ],
];
