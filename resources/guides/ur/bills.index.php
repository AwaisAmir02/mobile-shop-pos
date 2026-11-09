<?php

// Guide (Roman Urdu): Bills and Bill Payment History. Labels stay in English, exactly as on screen.

return [
    'title' => 'Bills',
    'summary' => 'Customer ka bijli, gas, phone ya koi aur bill apne account se jama karein, aur darj karein unhon ne aap ko kitne diye.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Bill kaise jama karein',
            'steps' => [
                'Category (bijli, gas…) aur Provider / Company chunein. Region / Province se providers ki list chhoti ho jati hai.',
                'Bill se Consumer Number aur Consumer Name likhein.',
                'Customer chunein, ya Walk-in rehne dein.',
                'Bill Amount likhein aur Send From mein woh account chunein jis se bill jama ho raha hai.',
                'Service Charge dekh lein, Payment Status set karein, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => '+ New Provider', 'text' => 'Jo company list mein nahin, use shamil karta hai. + New Provider (with a region) uska region bhi set karta hai.'],
                ['label' => 'Send From', 'text' => 'Aap ka konsa account (JazzCash, bank…) bill jama kar raha hai. + New Shop Account se naya account banta hai.'],
                ['label' => 'Service Charge', 'text' => 'Aap ki fees. Settings mein likhe commission % se khud aa jati hai, aap badal sakte hain.'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid: customer ne aap ko paise diye? Partial ho to Amount Paid likhein.'],
                ['label' => 'Received Into', 'text' => 'Customer ka paisa cash mein aaya ya kis account mein, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Total Collected', 'text' => 'Bill Amount aur Service Charge, Discount minus kar ke.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'Customer ke liye PDF receipt.'],
                ['label' => 'Log Another', 'text' => 'Agle bill ke liye khaali form.'],
                ['label' => 'Bill Payment History', 'text' => 'Upar wala link: aap ke jama kiye saare bills.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Bill Payment History',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch dinon ke bills dekhein. Category, Provider aur Payment Status se aur chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Total Collected', 'text' => 'Dikhaye gaye bills par customers ne kitna diya. Total Owed by Customers woh hai jo abhi baqi hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: customer ne baqi raqam ka kuch ya saara hissa diya. Amount Paid Now likhein.'],
                ['label' => 'Mark as Paid', 'text' => 'Customer ne saari baqi raqam de di.'],
                ['label' => 'Download', 'text' => 'Us bill ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'bills-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir bill payment cancel kar deta hai. List mein Cancelled likh kar rehti hai aur totals mein nahin ginti. Yeh wapas nahin hota.', 'needs' => 'bills-edit-cancel'],
                ['label' => 'New Bill Payment', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
