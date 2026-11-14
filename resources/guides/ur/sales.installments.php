<?php

// Guide (Roman Urdu): Instalments. Labels match the screen exactly.

return [
    'title' => 'Instalments',
    'summary' => 'Qiston par biki har sale, late wali sab se pehle, taake pata ho kis ko call karna hai.',
    'sections' => [
        [
            'id' => 'selling',
            'heading' => 'Qiston par bechna',
            'steps' => [
                'Sales register par Payment mein Instalments chunein.',
                'Aaj li gayi Down Payment, kitni mahana Instalments, aur First Due tareekh likhein. Plan neeche nazar aa jayega.',
                'Customer chunein, phir Complete Sale.',
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Open', 'text' => 'Jin plans mein abhi paise aane baaki hain.'],
                ['label' => 'Due now', 'text' => 'Sirf woh plans jin ki qist aaj ki hai ya late hai.'],
                ['label' => 'Paid off', 'text' => 'Jo plans poore ada ho chuke.'],
                ['label' => 'Status', 'text' => 'Kya late hai aur kab se, phir agli tareekh aur raqam.'],
                ['label' => 'Left', 'text' => 'Us sale par kul kitna baaki hai.'],
            ],
        ],
        [
            'id' => 'payments',
            'heading' => 'Payment lena',
            'intro' => 'Customer kholein aur sale par Record Payment karein, jaise kisi bhi baaki raqam ke liye. Payment hamesha sab se pehli qist mein jaati hai. Late qistein customer ke WhatsApp reminder mein shamil hoti hain; jo abhi due nahi, un ka sirf zikr hota hai.',
        ],
    ],
];
