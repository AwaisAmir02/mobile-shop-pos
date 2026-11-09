<?php

// Guide (Roman Urdu): Vendors list. Labels stay in English, exactly as on screen.

return [
    'title' => 'Vendors',
    'summary' => 'Woh suppliers jin se aap maal khareedte hain, aur har ek ke kitne paise aap par baqi hain.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Search', 'text' => 'Naam ya phone number se vendor dhoondein.'],
                ['label' => 'Owed', 'text' => 'Is vendor ke aap par kitne baqi hain, zyada tar Stock In par Unpaid ya Partial save hue maal ke.'],
            ],
        ],
        [
            'id' => 'adding',
            'heading' => 'Naya vendor',
            'items' => [
                ['label' => 'Add Vendor', 'text' => 'Sirf Name zaroori hai. Stock In par + New Vendor se bhi vendor ban jata hai.'],
                ['label' => 'Company / Shop Name', 'text' => 'Karobar ka naam, agar shakhs ke naam se alag ho.'],
                ['label' => 'Notes', 'text' => 'Yaad rakhne wali baat: payment ki sharait, doosra number.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'Har vendor ka ⋯ menu',
            'items' => [
                ['label' => 'View', 'text' => 'Vendor ka page, un se khareeda gaya sab kuch.'],
                ['label' => 'Edit', 'text' => 'Unki tafseel badlein.'],
                ['label' => 'Delete', 'text' => 'Sirf us vendor ke liye jis ki koi Stock In entry nahin, taake khareed ka record poora rahe.'],
            ],
        ],
        [
            'id' => 'paying',
            'heading' => 'Vendor ko paise dena',
            'intro' => 'Vendor ko payment unke diye hue maal ke against Stock In History mein darj hoti hai. Vendor ke page par ek button wahan le jata hai.',
        ],
    ],
];
