<?php

// Guide (Roman Urdu): Wallet Load and its history. Labels stay in English, exactly as on screen.

return [
    'title' => 'Wallet Load',
    'summary' => 'Customers ke liye mobile wallet ka len den. Cash In: customer aap ko cash deta hai aur aap apne wallet se bhejte hain. Cash Out: woh aap ke wallet mein bhejta hai aur aap use cash dete hain.',
    'sections' => [
        [
            'id' => 'logging',
            'heading' => 'Len den kaise darj karein',
            'steps' => [
                'Upar Cash In ya Cash Out chunein.',
                'Apna account chunein: Cash In ke liye Send From, Cash Out ke liye Received Into.',
                'Dusre shakhs ke wallet ka Account Name aur Account / Mobile Number likhein.',
                'Amount likhein aur Service Charge dekh lein.',
                'Payment mein likhein customer ne ab tak kitna diya, phir Save dabayein.',
            ],
        ],
        [
            'id' => 'form',
            'heading' => 'Form mein',
            'items' => [
                ['label' => 'Service Charge', 'text' => 'Aap ki fees. Settings mein likhe commission % se khud aa jati hai, aap badal sakte hain.'],
                ['label' => 'Fee is already included in the amount above', 'text' => 'Tick karein jab customer ki raqam mein aap ki fees pehle se shamil ho. Neeche wali line misaal se batati hai kitna jayega aur aap kitna rakhenge.'],
                ['label' => 'Cash Received', 'text' => 'Cash In mein: customer ne kitna cash diya. Status (Paid, Partial ya Unpaid) isi se banta hai.'],
                ['label' => 'Amount Received in Account', 'text' => 'Cash Out mein: customer ne aap ke account mein kitna bheja.'],
                ['label' => 'Still Owed by Customer', 'text' => 'Jab total se kam aaya ho: customer ke saath kitna hisaab baqi hai. Cash Out mein iska naam Still Owed to Customer hai.'],
            ],
        ],
        [
            'id' => 'saved',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Download Receipt', 'text' => 'Customer ke liye PDF receipt.'],
                ['label' => 'Log Another', 'text' => 'Agle len den ke liye khaali form.'],
                ['label' => 'Wallet Load History', 'text' => 'Upar wala link: saare len den.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Wallet Load History',
            'items' => [
                ['label' => 'Cash in or cash out', 'text' => 'Dono lists ke darmiyan badlein. Provider, Shop Account aur Payment Status se chhanti karein, Clear se filters hat jate hain.'],
                ['label' => 'Net Service Fees', 'text' => 'Dikhaye gaye len den par aap ne fees mein kitna kamaya. By provider raqam ko JazzCash, Easypaisa waghera mein baant kar dikhata hai.'],
                ['label' => 'Add Payment', 'text' => '⋯ menu mein: baad mein aane wala paisa darj karein. Mark as Paid poori raqam chukta kar deta hai.'],
                ['label' => 'Download', 'text' => 'Us len den ki PDF receipt.'],
                ['label' => 'Edit', 'text' => 'Tafseel ya raqam badlein.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir len den cancel kar deta hai. List mein Cancelled likh kar rehta hai. Yeh wapas nahin hota.', 'needs' => 'wallet-loads-edit-cancel'],
                ['label' => 'New Wallet Load', 'text' => 'Wapas form par.'],
            ],
        ],
    ],
];
