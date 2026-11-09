<?php

// Guide (Roman Urdu): Stock In. Labels stay in English, exactly as on screen.

return [
    'title' => 'Stock In',
    'summary' => 'Supplier se aane wala maal darj karein. Supplier ke bill ke saare products ek receipt mein daalein, phir ek dafa save karein.',
    'sections' => [
        [
            'id' => 'quick-start',
            'heading' => 'Maal kaise darj karein',
            'steps' => [
                'Product chunein. List lambi ho to Category aur Sub-Category se chhoti kar lein.',
                'Quantity, Cost Price aur Selling Price likhein.',
                ['text' => 'Agar product ki expiry hoti hai to Expiry Date likhein.', 'feature' => 'expiry_tracking'],
                'Add to Receipt dabayein. Har product ke liye Add More se dobara karein.',
                'Vendor & Payment bharein, phir Save Receipt dabayein. Stock foran barh jata hai.',
            ],
        ],
        [
            'id' => 'product',
            'heading' => 'Receipt mein product daalna',
            'items' => [
                ['label' => 'Product', 'text' => 'List mein har product ka maujooda stock likha hota hai. + New Product se yahin naya product ban jata hai.'],
                ['label' => 'Cost Price', 'text' => 'Supplier ko ek ke kitne diye. Aap ka munafa isi se nikalta hai.'],
                ['label' => 'Selling Price', 'text' => 'Register par yeh batch is qeemat par bikega. Purana stock pehle bikta hai, apni qeemat par.'],
                ['label' => 'Expiry Date', 'text' => 'Aakhri din jab tak bech sakte hain. Product mein Shelf Life likhi ho to khud bhar jati hai.', 'feature' => 'expiry_tracking'],
                ['label' => '×', 'text' => 'Receipt ki line par: save karne se pehle us line ko hata deta hai.'],
            ],
        ],
        [
            'id' => 'payment',
            'heading' => 'Vendor & Payment',
            'intro' => 'Yeh hissa receipt ke saare products par lagta hai.',
            'items' => [
                ['label' => 'Vendor', 'text' => 'Maal kis ne diya. + New Vendor se naya vendor banta hai.', 'needs' => 'vendors'],
                ['label' => 'Payment Status', 'text' => 'Paid, Partial ya Unpaid. Partial ya Unpaid ka matlab hai supplier ke paise aap par baqi hain. Yeh unke vendor page par nazar aata hai.'],
                ['label' => 'Amount Paid', 'text' => 'Aap ne asal mein kitna diya. Raqam likhne se status khud set ho jata hai.'],
                ['label' => 'Paid From', 'text' => 'Paisa cash se gaya ya kis account se, aap ke ledger ke liye.', 'feature' => 'ledger'],
                ['label' => 'Date Received', 'text' => 'Maal kis din aaya. Aane wali tareekh nahin chalti.'],
                ['label' => 'Note', 'text' => 'Yaad rakhne wali baat, jaise supplier ke bill ka number.'],
                ['label' => 'Total Cost', 'text' => 'Poori receipt cost price par.'],
            ],
        ],
        [
            'id' => 'history',
            'heading' => 'Save karne ke baad',
            'items' => [
                ['label' => 'Stock In History', 'text' => 'Upar wala link. Har save ki hui receipt, baad mein dekhne, badalne ya payment karne ke liye. Wahan ke tabs Write-offs tak bhi le jate hain, kharab, gum ya expire hue maal ke liye.'],
                ['label' => 'Expiring & Expired', 'text' => 'Wahan ek aur tab: expire ho chuka ya hone wala stock.', 'feature' => 'expiry_tracking'],
            ],
        ],
    ],
];
