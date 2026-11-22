<?php

// Guide (Roman Urdu): Promotions. Labels stay in English, exactly as on screen.

return [
    'title' => 'Promotions',
    'summary' => 'Kuch arse ke liye qeemat mein kami: percentage ya har piece par mukarrar raqam kam, do tareekhon ke darmiyan, chune hue products, variant groups, categories ya sab cheezon par. New Sale ise khud kam kar deta hai aur receipt par line ke saath dikhata hai. WhatsApp aur online store orders par promotions nahi lagti.',
    'sections' => [
        [
            'id' => 'adding',
            'heading' => 'Promotion banana',
            'items' => [
                ['label' => 'Name', 'text' => 'Receipt par us line ke saath chhapta hai jis par kami hui, maslan Eid Sale.'],
                ['label' => 'Discount', 'text' => '% off line mein se utna hissa kam karta hai. Rs off each har piece (ya wazan wali cheez mein har kg) par itni raqam kam karta hai, line ki kul raqam se zyada kabhi nahi.'],
                ['label' => 'Applies to', 'text' => 'Sab cheezein, ya sirf woh products, variant groups ya categories jo aap chunein. Variant group us ke har size aur rang par lagta hai.'],
                ['label' => 'Starts / Ends', 'text' => 'Shuru ki tareekh se khatam ki tareekh tak chalti hai, dono din shamil. Ends khali chhorein to pause karne tak chalti rahegi. Pichli tareekh ki sale ko us din chalne wali promotions milti hain.'],
            ],
        ],
        [
            'id' => 'register',
            'heading' => 'Register par',
            'intro' => 'Har line par dikhta hai ke kaunsi promotion lagi aur kitna kam hua. Cashier ise hata nahi sakta. Agar do promotions ek line par lagein to woh lagti hai jo zyada kam kare. Cashier ka line par likha discount pehle kam hota hai, aur promotion baqi raqam par lagti hai. Sale edit karne par woh promotion rehti hai jis ke saath bechi gayi thi, nayi nahi lagti.',
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Status', 'text' => 'Running, Starts (aane wali tareekh), Ended, ya Paused.'],
                ['label' => 'Given so far', 'text' => 'Ab tak kitna kam kiya, kitni sales par. Cancel shuda sales shamil nahi.'],
                ['label' => 'Pause', 'text' => 'Nayi sales ke liye foran rok deta hai. Turn back on se dobara shuru karein.'],
                ['label' => 'Edit', 'text' => 'Kisi sale mein istemal hone ke baad sirf naam aur tareekhein badal sakti hain, taa ke purani sales aur un ki edits theek rahein. Discount badalna ho to ise khatam kar ke nayi banayein.'],
                ['label' => 'Delete', 'text' => 'Sirf us promotion ke liye jo abhi kisi sale mein istemal nahi hui.'],
            ],
        ],
    ],
];
