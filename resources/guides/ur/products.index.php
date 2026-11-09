<?php

// Guide (Roman Urdu): Products list and the Add Product form / wizard
// (section 'add-product'). Labels stay in English, exactly as on screen.

return [
    'title' => 'Products',
    'summary' => 'Jo kuch aap bechte hain, qeemat aur stock ke saath. Naye products yahan banayein. Baad mein aane wala maal Stock In se darj hota hai.',
    'sections' => [
        [
            'id' => 'list',
            'heading' => 'List',
            'items' => [
                ['label' => 'Search', 'text' => 'Naam ya model se product dhoondein.'],
                ['label' => 'Search by barcode', 'text' => 'Isi box mein barcode scan karein ya likhein.', 'feature' => 'barcode'],
                ['label' => 'Category', 'text' => 'Sirf ek category dekhein, jaise Mobile ya Accessory.'],
                ['label' => 'Stock', 'text' => 'Hara: stock mein hai. Peela: Low, kam reh gaya. Laal: Out of stock. Archived products chhupe rehte hain (neeche Archive dekhein).'],
                ['label' => 'Export', 'text' => 'List PDF ya Excel file mein download karein.'],
            ],
        ],
        [
            'id' => 'row-menu',
            'heading' => 'Har product ka ⋯ menu',
            'items' => [
                ['label' => 'View details', 'text' => 'Product ka page, uske batches aur sales ke saath.'],
                ['label' => 'Edit', 'text' => 'Naam, category, qeemat waghera badlein.'],
                ['label' => 'Print Label', 'text' => 'Is product ke barcode labels print karein: kitni copies, layout, aur qeemat dikhani hai ya nahin.', 'feature' => 'barcode'],
                ['label' => 'Add to WhatsApp', 'text' => 'Product aap ke WhatsApp catalogue mein aa jata hai. On WhatsApp ka matlab hai pehle se list hai; hatane ke liye us par tap karein.', 'feature' => 'whatsapp_ordering'],
                ['label' => 'Archive', 'text' => 'Sirf un products ke liye jin ka stock khatam ho. Product chhup jata hai aur aap ke plan mein nahin ginta. Dobara stock aane par khud wapas aa jata hai.'],
                ['label' => 'Restore', 'text' => 'Archived product wapas le aata hai.'],
                ['label' => 'Delete', 'text' => 'Pehle poochta hai, phir product hamesha ke liye hata deta hai.'],
            ],
        ],
        [
            'id' => 'more-actions',
            'heading' => 'Add Product ke saath wala ⋯',
            'items' => [
                ['label' => 'Add Variant Group', 'text' => 'Ek hi cheez ke alag sizes ya colours ko ek group mein jorta hai, ya ek saath naye variants banata hai.', 'feature' => 'variants'],
                ['label' => 'Import from Excel', 'text' => 'Excel sheet se bohat se products ek saath daalein. Pehle template download karke bharein.', 'feature' => 'import'],
                ['label' => 'Generate missing barcodes', 'text' => 'Jin products ka barcode nahin, har ek ko alag barcode de deta hai, labels par print karne ke liye.', 'feature' => 'barcode'],
            ],
        ],
        [
            'id' => 'labels',
            'heading' => 'Ek saath bohat se labels',
            'feature' => 'barcode',
            'intro' => 'Jin products ke labels chahiye un ke saath wala box tick karein, phir Print label sheet dabayein. Clear se selection khaali ho jati hai.',
        ],
        [
            'id' => 'add-product',
            'heading' => 'Naya product banana',
            'steps' => [
                'Add Product dabayein.',
                'Main Category chunein aur Product Name likhein. Baqi fields category ke hisaab se badalte hain: mobile ke liye Brand, Model aur IMEI / Serial, accessory ke liye Sub-Category.',
                'Selling Price likhein. Cost Price zaroori nahin, lekin munafe ki reports ko chahiye.',
                ['text' => 'Next dabayein. One product chunein, ya Sizes or colours taake har size aur colour ka variant ek saath ban jaye.', 'feature' => 'variants'],
                'Stock Qty mein likhein abhi aap ke paas kitne hain, phir Add Product dabayein.',
            ],
            'items' => [
                ['label' => 'Image', 'text' => 'Zaroori nahin. Tasveer se register par, aur WhatsApp par agar istemal karte hain, product pehchanna aasaan hota hai.'],
                ['label' => 'Sold By', 'text' => 'Poori cheezon ke liye Piece. Kg, metre aur baqi se 1.5 jaisi miqdaar bech sakte hain.', 'feature' => 'measured_units'],
                ['label' => 'Sizes', 'text' => 'Sizes or colours mein: comma laga kar likhein, jaise S, M, L. Colours bhi isi tarah. Har jor ka alag variant banta hai, apne stock aur barcode ke saath.', 'feature' => 'variants'],
                ['label' => 'Shelf Life (days)', 'text' => 'Zaroori nahin. Stock In par expiry date khud bhar deta hai.', 'feature' => 'expiry_tracking'],
                ['label' => 'Barcode', 'text' => 'Zaroori nahin. Dabbe par chhapa barcode likhein ya scan karein, ya naye ke liye Generate dabayein.', 'feature' => 'barcode'],
                ['label' => 'Editing a product with stock', 'text' => 'Stock In se maal aane ke baad qeemat batches se aati hai, is liye qeemat ke box band ho jate hain. Qeemat Stock In ki entry par badlein.'],
            ],
        ],
    ],
];
