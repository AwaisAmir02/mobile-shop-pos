<?php

// Guide (Roman Urdu): Cash & Balances. Labels stay in English, exactly as on screen.

return [
    'title' => 'Cash & Balances',
    'summary' => 'Aap ke cash aur har account (JazzCash, Easypaisa, bank) mein kitna hai, aur har din kya aaya aur kya gaya. Yeh woh sab jorta hai jo app pehle se darj karti hai: sales, udhaar, counter services, wallet loads, bills, vendor payments, kharche aur payroll.',
    'sections' => [
        [
            'id' => 'start',
            'heading' => 'Shuru kaise karein',
            'steps' => [
                'Balances par Cash in hand aur har account par Set opening balance dabayein, aur likhein din ke shuru mein kitna tha.',
                'Uske baad jo bhi payment aap darj karenge, sahi balance khud badal jayega.',
                'Din ke aakhir mein Count dabayein, phir gina hua cash ya JazzCash, Easypaisa ya bank app ka balance likhein.',
            ],
        ],
        [
            'id' => 'balances',
            'heading' => 'Balances',
            'items' => [
                ['label' => 'In · Out', 'text' => 'Opening balance ke baad kitna aaya aur kitna gaya.'],
                ['label' => 'Last count', 'text' => 'Aap ki aakhri ginti, aur woh zyada thi, kam thi ya barabar. Farq durusti ke taur par rakh liya jata hai, taake balance aap ki ginti se mile.'],
                ['label' => 'Move money', 'text' => 'Cash bank mein jama kiya, bank se JazzCash bhara, account se cash nikala: aap ke apne cash aur accounts ke darmiyan paisa.'],
                ['label' => 'Not marked cash or account', 'text' => 'Woh paisa jis par kisi ne Cash ya account nahin chuna, is liye kisi balance mein nahin. Payment darj karte waqt chunein taake balance sahi rahe.'],
            ],
        ],
        [
            'id' => 'flow',
            'heading' => 'Daily flow',
            'items' => [
                ['label' => 'Dates', 'text' => 'Ek din ya kuch din chunein. Shuru mein aaj ka din hota hai.'],
                ['label' => 'Cash came in', 'text' => 'Chuni hui tareekhon ke totals: cash aaya aur gaya, accounts mein aaya aur gaya, aur counter services ki fees.'],
                ['label' => 'Cash or account', 'text' => 'Har din cash aur har account ki line: kitna aaya, kitna gaya, aur kul farq. Naam par tap karein to pata chalega kahan se: sales, wallet loads, kharche waghera.'],
                ['label' => 'Every entry that day', 'text' => 'Totals ke peeche har payment.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Moves & counts',
            'items' => [
                ['label' => 'Cancel', 'text' => 'Ghalat opening, move ya count ko khatam karta hai. List mein Cancelled likh kar rehta hai aur ginti mein nahin aata.'],
            ],
        ],
    ],
];
