<?php

// Guide (Roman Urdu): Dues Aging report. Labels stay in English, exactly as on screen.

return [
    'title' => 'Dues Aging',
    'summary' => 'Kis ne shop ke kitne paise dene hain aur kab se: har customer ki baqi raqam 0–30, 31–60, 61–90 aur 90 din se zyada mein taqseem. Is se tay karein ke pehle kis se wasooli karni hai. Bills aur udhaar loans alag dikhaye jate hain, kabhi jama nahin kiye jate.',
    'sections' => [
        [
            'id' => 'totals',
            'heading' => 'Totals',
            'items' => [
                ['label' => 'Bills owed', 'text' => 'Sales aur services ki tamam baqi raqam, wohi jo Udhaar list ginti hai.'],
                ['label' => 'Overdue bills', 'text' => 'In bills ka woh hissa jo sale par lagi due date (customer ki payment terms se) guzar chuka hai.'],
                ['label' => 'Udhaar owed', 'text' => 'Udhaar loans jo abhi wapas nahin aaye. Wapsi pehle sab se purane loan ko ada karti hai.'],
                ['label' => 'Over 90 days', 'text' => 'Teen mahine se zyada purani baqi raqam: jis ki wasooli sab se mushkil hoti hai.'],
            ],
        ],
        [
            'id' => 'table',
            'heading' => 'Table',
            'intro' => 'Har customer jis ke zimme kuch baqi hai, sab se zyada wala pehle. Har bill apni tareekh se gina jata hai, is liye ek customer ki raqam kai columns mein ho sakti hai.',
            'items' => [
                ['label' => 'Show', 'text' => 'Bills aur Udhaar loans ke darmiyan badlein.'],
                ['label' => 'Area / route', 'text' => 'Sirf ek area ke customers, wasooli ka chakkar plan karne ke liye. Area customer par set karein.'],
                ['label' => 'Overdue only', 'text' => 'Sirf woh customers jin ka koi bill due date se guzar chuka hai.'],
                ['label' => 'Export', 'text' => 'List ko PDF ya Excel mein download karein, chune hue filters ke saath.'],
            ],
        ],
    ],
];
