<?php

// Guide (Roman Urdu): Sales register (New Sale). Labels stay in English, exactly as on screen.

return [
    'title' => 'New Sale',
    'summary' => 'Yeh sales register hai. Products dhoondein, cart banayein, payment lein aur sale mukammal karein. Uske foran baad receipt khul jati hai.',
    'sections' => [
        [
            'id' => 'quick-start',
            'heading' => 'Sale kaise karein',
            'steps' => [
                'Upar search box mein product ka naam likhein (F2 dabane se cursor wahan chala jata hai) aur product par tap karein, woh cart mein aa jayega.',
                ['text' => 'Ya search box mein cursor rakh kar barcode scan karein. Product seedha cart mein aa jata hai.', 'feature' => 'barcode'],
                'Cart ki line par − aur + se quantity kam ya zyada karein.',
                'Payment mein Cash, Partial ya Udhaar chunein.',
                'Complete Sale dabayein (ya F9). Stock kam ho jata hai aur receipt khul jati hai.',
            ],
        ],
        [
            'id' => 'finding',
            'heading' => 'Products dhoondna',
            'items' => [
                ['label' => 'Search products', 'text' => 'Likhte hi results aa jate hain. Esc dabane se search saaf ho jati hai.'],
                ['label' => 'Frequent products', 'text' => 'Jab cart khaali ho, sab se zyada bikne wale products yahan nazar aate hain. Kisi par tap karein, cart mein aa jayega.'],
                ['label' => 'Back to results', 'text' => 'Jo product sizes ya colours mein ho, pehle uske variants dikhte hain. Customer wala variant chunein, ya wapas results par jayein.', 'feature' => 'variants'],
                ['label' => 'Add to Cart', 'text' => 'Wazan ya lambai se bikne wale products pehle poochte hain kitna chahiye, jaise 1.5 kg, phir cart mein jate hain.', 'feature' => 'measured_units'],
            ],
        ],
        [
            'id' => 'cart',
            'heading' => 'Cart',
            'items' => [
                ['label' => '− and +', 'text' => 'Ek kam ya ek zyada. Box mein number khud bhi likh sakte hain. Stock se zyada nahin bech sakte.'],
                ['label' => 'Price & discount', 'text' => 'Us line ke liye do box khulte hain. Price each se sirf is dafa alag qeemat par bechein. Discount (Rs) poori line par itni raqam kam kar deta hai.'],
                ['label' => 'Price overridden', 'text' => 'Jis line ki qeemat aap ne badli ho, us par yeh likha aata hai. Below cost price ka matlab hai aap ghaate mein bech rahe hain.'],
                ['label' => 'ⓘ next to the name', 'text' => 'Batata hai yeh product aap ko kitne ka para.', 'needs' => 'sales-view-cost-price'],
                ['label' => 'Expires', 'text' => 'Is line ka stock jis batch se bikega, uski sab se pehli expiry date. Purana stock pehle bikta hai.', 'feature' => 'expiry_tracking'],
                ['label' => '×', 'text' => 'Line ko cart se nikaal deta hai.'],
                ['label' => 'Checkout', 'text' => 'Phone par total screen ke neeche rehta hai. Checkout dabane se payment wala hissa khul jata hai.'],
            ],
        ],
        [
            'id' => 'checkout',
            'heading' => 'Payment lena',
            'items' => [
                ['label' => 'Cash', 'text' => 'Customer poori raqam abhi de raha hai.'],
                ['label' => 'Partial', 'text' => 'Customer kuch raqam abhi de raha hai. Woh Amount Paid Now mein likhein. Baqi baqaya rehta hai, is liye customer chunein.'],
                ['label' => 'Udhaar', 'text' => 'Abhi kuch nahin mila. Poori raqam customer ke khaate mein jati hai, is liye customer chunna zaroori hai.'],
                ['label' => 'Paid by', 'text' => 'Paisa kaise aaya: Cash ya aap ka koi account (JazzCash, Easypaisa, bank). + New account se naya account banta hai. Hisaab nahin rakhte to Not recorded rehne dein.'],
                ['label' => 'Customer', 'text' => 'Poori payment wali sale mein Walk-in rehne dein. + New Customer se sale chhore baghair naya customer ban jata hai.'],
                ['label' => 'Due After Sale', 'text' => 'Sale save hone ke baad kitni raqam baqi rahegi.'],
                ['label' => 'Today', 'text' => 'Pichhle din ki sale darj karni ho to is par tap karein. Aane wali tareekh nahin chalti.'],
                ['label' => '+ Add discount', 'text' => 'Poore bill par discount (Invoice Discount), jo subtotal se kam hota hai.'],
                ['label' => 'Complete Sale', 'text' => 'Sale save karta hai, stock kam karta hai aur receipt khol deta hai.'],
            ],
        ],
        [
            'id' => 'shortcuts',
            'heading' => 'Keyboard shortcuts',
            'items' => [
                ['label' => 'F2', 'text' => 'Search box par chale jayein, likhne ya scan karne ke liye tayyar.'],
                ['label' => 'F9', 'text' => 'Sale mukammal karein.'],
                ['label' => 'Esc', 'text' => 'Search saaf karein.'],
            ],
        ],
    ],
];
