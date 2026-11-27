<?php

// Guide (Roman Urdu): Service Menu. Labels match the screen exactly.

return [
    'title' => 'Service Menu',
    'summary' => 'Aap ki services un ki qeemat aur waqt ke saath, aur memberships aur packages jo aap bechte hain. Yeh sab New Sale par bill ki line ke taur par bikti hain, products ke saath.',
    'sections' => [
        [
            'id' => 'services',
            'heading' => 'Services',
            'intro' => 'Har service ki qeemat aur waqt.',
            'items' => [
                [
                    'label' => 'New Service',
                    'text' => 'Naam, category, qeemat aur waqt. Waqt se calendar par booking ki lambai banti hai.',
                ],
                [
                    'label' => 'Places',
                    'text' => '1 se zyada ho to class ban jati hai: itne log ek hi waqt book kar sakte hain.',
                ],
            ],
        ],
        [
            'id' => 'memberships',
            'heading' => 'Memberships aur packages',
            'intro' => 'Membership waqt ke hisaab se chalti hai; package sessions ki ginti hai.',
            'items' => [
                [
                    'label' => 'Membership (by time)',
                    'text' => 'Itne din ya mahine chalti hai, aur jitne din aap ijazat dein utne freeze ho sakti hai.',
                ],
                [
                    'label' => 'Package (by sessions)',
                    'text' => 'Sessions ki ginti, chahein to ek waqt ke andar, un services par jo aap tick karein.',
                ],
            ],
        ],
    ],
];
