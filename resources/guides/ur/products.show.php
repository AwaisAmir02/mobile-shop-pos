<?php

// Guide (Roman Urdu): the product page. Labels stay in English, exactly as on screen.

return [
    'title' => 'Product page',
    'summary' => 'Ek product ki poori tafseel: qeemat aur stock, kin batches mein aaya, aur kahan bika.',
    'sections' => [
        [
            'id' => 'buttons',
            'heading' => 'Upar',
            'items' => [
                ['label' => 'Add Stock', 'text' => 'Is product ka naya maal darj karne ke liye Stock In kholta hai.', 'needs' => 'stock-ins'],
                ['label' => 'Edit', 'text' => 'Products list par is product ka form kholta hai.'],
            ],
        ],
        [
            'id' => 'overview',
            'heading' => 'Overview',
            'items' => [
                ['label' => 'Selling price', 'text' => 'Register abhi yeh qeemat lagata hai. Yeh agle bikne wale batch se aati hai.'],
                ['label' => 'In stock', 'text' => 'Aap ke paas kitne hain.'],
                ['label' => 'Cost price', 'text' => 'Agla bikne wala batch aap ko kitne ka para.', 'needs' => 'sales-view-cost-price'],
                ['label' => 'Sold, last 30 days', 'text' => 'Pichhle 30 din mein kitne bike.', 'needs' => 'sales'],
                ['label' => 'Details', 'text' => 'Category, brand, unit, barcode waghera, jaise product mein likhe hain.'],
            ],
        ],
        [
            'id' => 'tabs',
            'heading' => 'Baqi tabs',
            'items' => [
                ['label' => 'Variants', 'text' => 'Sirf size ya colour group wale product ke liye: har variant apni qeemat aur stock ke saath. Open se us variant par chale jayein.', 'feature' => 'variants'],
                ['label' => 'Batches', 'text' => 'Har dafa jab yeh product Stock In se aaya: kitne bache hain, cost aur selling price, aur vendor ko paise diye ya nahin. Purane dekhne ke liye See all in Stock In History.', 'needs' => 'stock-ins'],
                ['label' => 'Expires', 'text' => 'Batches mein har batch ki expiry date. Laal ka matlab hai expire ho chuka aur stock abhi baqi hai.', 'feature' => 'expiry_tracking', 'needs' => 'stock-ins'],
                ['label' => 'Sales', 'text' => 'Is product ki taaza sales.', 'needs' => 'sales'],
            ],
        ],
    ],
];
