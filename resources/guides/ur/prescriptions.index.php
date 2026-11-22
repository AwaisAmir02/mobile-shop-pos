<?php

// Guide (Roman Urdu): Prescription Register. Labels stay in English, exactly as on screen.

return [
    'title' => 'Prescription Register',
    'summary' => 'Har us dawai ki sale jise aap ne Prescription only ya Controlled mark kiya, ek hi silsile ke number ke saath: tareekh, dawai aur miqdaar, patient, aur prescriber ya emergency sale ki wajah. Kaunsi dawaiyan mark karni hain yeh aap ki apni marzi hai; koi sarkari list nahin lagti.',
    'sections' => [
        [
            'id' => 'filters',
            'heading' => 'Kya dekhna hai',
            'items' => [
                ['label' => 'From / To', 'text' => 'Kin tareekhon ki sales dikhani hain.'],
                ['label' => 'Show', 'text' => 'Sab entries, sirf Controlled dawaiyan, sirf Emergency sales (prescriber ke baghair), ya Details missing: woh sales jo register par nahin banin, maslan WhatsApp ya online orders, jin ka prescriber abhi likhna hai.'],
                ['label' => 'Print', 'text' => 'Register jaise nazar aa raha hai waise print karta hai, maslan inspector ke liye.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Entries',
            'items' => [
                ['label' => 'No.', 'text' => 'Register number. Poori shop mein aage chalta hai aur dobara istemal nahin hota.'],
                ['label' => 'Prescriber', 'text' => 'Doctor aur registration number, ya Emergency aur us ki wajah. Bharne ya theek karne ke liye sale kholein aur Add details ya Edit details istemal karein.'],
                ['label' => 'Sale', 'text' => 'Sale kholta hai. Cancel shuda sale register mein rehti hai, cancelled likhi hoti hai, aur Entries mein nahin ginti.'],
            ],
        ],
    ],
];
