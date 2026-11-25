<?php

// Guide (Roman Urdu): Cheques. Labels match the screen exactly.

return [
    'title' => 'Cheques',
    'summary' => 'Customers se mile aur vendors ko diye gaye post-dated cheques. Cheque clear hone tak kuch ada nahin karta; clear hone ke baad bounce ho to jo ada hua tha woh dobara baqi ho jata hai.',
    'sections' => [
        [
            'id' => 'record',
            'heading' => 'Cheque darj karna',
            'intro' => 'Jis din cheque mile (ya dein) usi din darj karein, cheque par likhi tareekh ke saath.',
            'items' => [
                [
                    'label' => 'Record Cheque',
                    'text' => 'Customer ya vendor, cheque number, bank, raqam aur cheque ki tareekh.',
                ],
                [
                    'label' => 'Received / Issued to vendors',
                    'text' => 'Apne paas mojood aur diye gaye cheques ke darmiyan badlein.',
                ],
            ],
        ],
        [
            'id' => 'actions',
            'heading' => 'Jab bank kuch kare',
            'intro' => 'Har cheque ke menu se.',
            'items' => [
                [
                    'label' => 'Deposited',
                    'text' => 'Bank mein jama karwa diya; account chunein.',
                ],
                [
                    'label' => 'Cleared',
                    'text' => 'Paise aa gaye. Customer ka cheque pehle udhaar, phir purane bills ada karta hai; vendor ka cheque purana stock.',
                ],
                [
                    'label' => 'Bounced',
                    'text' => 'Cheque wapas aa gaya. Agar clear ho chuka tha to jo ada hua dobara baqi, aur paise account se nikal jate hain.',
                ],
                [
                    'label' => 'Cancel',
                    'text' => 'Ghalti se darj hua ya bank se pehle wapas kar diya gaya cheque.',
                ],
            ],
        ],
    ],
];
