<?php

// Guide (Roman Urdu): Return Items. Labels stay in English, exactly as on screen.

return [
    'title' => 'Return Items',
    'summary' => 'Ek sale ka kuch ya saara saman wapas lein. Stock usi batch par wapas jata hai jis se bika tha, aur return usi din gina jata hai jis din hua. Sale khud waisi hi rehti hai.',
    'sections' => [
        [
            'id' => 'what',
            'heading' => 'Kya wapas aa raha hai?',
            'items' => [
                ['label' => 'Quantity', 'text' => 'Har item kitna wapas aa raha hai, jitna wapas hona baqi hai us tak. Baqi khali chhor dein.'],
                ['label' => 'Return everything', 'text' => 'Sale par jo kuch baqi hai sab bhar deta hai.'],
                ['label' => 'Back to stock', 'text' => 'Shelf par wapas jata hai aur dobara bik sakta hai, usi cost par jis par bika tha.'],
                ['label' => 'Damaged', 'text' => 'Wapas aa kar foran write-off ho jata hai, taake nuqsan Write-offs mein nazar aaye.'],
            ],
        ],
        [
            'id' => 'refund',
            'heading' => 'Refund',
            'items' => [
                ['label' => 'Value of what\'s coming back', 'text' => 'Customer ne iske kitne diye the, line ya poore bill ke discount ke baad.'],
                ['label' => 'Taken off what\'s still owed', 'text' => 'Agar customer par is sale ka kuch baqi hai to return pehle us mein se kat-ta hai.'],
                ['label' => 'To hand back', 'text' => 'Baqi raqam jo paison mein wapas deni hai. Cash & Balances mein return ki tareekh par refund ke taur par nazar aati hai.'],
                ['label' => 'Refund From', 'text' => 'Cash ya shop account jis se paise wapas diye ja rahe hain.'],
                ['label' => 'Store credit', 'text' => 'Raqam customer ke store credit mein daal deta hai, taake agli sale par kharch ho. Walk-in customer ho to pehle customer chunein ya naya banayein. Jo shop paise wapas nahi deti us mein sirf yahi option hai.'],
            ],
        ],
        [
            'id' => 'limits',
            'heading' => 'Hadd',
            'intro' => 'Shop ka owner Settings > Returns mein tay karta hai ke sale ke kitne din baad tak return liya jaye, aur paise wapas diye jayen ya nahi. Us hadd ke baad sirf owner saman wapas le sakta hai, aur wajah likhni hoti hai. Jis sale ka return ho woh edit ya delete nahi ho sakti jab tak return cancel na ho.',
        ],
    ],
];
