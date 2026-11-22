<?php

// Guide (English): Used Phone Buyback. Labels match the screen exactly.

return [
    'title' => 'Used Phone Buyback',
    'summary' => 'Buy a used phone from a customer and put it straight into stock to resell. The price you pay becomes its cost, so profit is right when it sells.',
    'sections' => [
        [
            'id' => 'buy',
            'heading' => 'Buying a phone',
            'steps' => [
                'Write the seller\'s name. Add their phone and CNIC: it shows who sold you the phone if it is ever questioned.',
                'Enter the brand, model and IMEI (dial *#06# on the phone). Check the IMEI against the box.',
                'Choose the condition, and note battery health, PTA status, box and charger.',
                'Enter the price you paid and the price you want to sell it at.',
                'Pick where the money came from (cash or an account) and press Buy and add to stock.',
            ],
        ],
        [
            'id' => 'what-happens',
            'heading' => 'What happens',
            'items' => [
                ['label' => 'Products', 'text' => 'The phone becomes a product named "Brand Model (Used)" with one in stock, ready to sell on the Sales screen.'],
                ['label' => 'Cash & Balances', 'text' => 'The price paid goes out of the cash or account you picked.'],
                ['label' => 'IMEI', 'text' => 'A phone already in stock can\'t be bought in again. A phone you sold before goes back onto its old product.'],
            ],
        ],
        [
            'id' => 'list',
            'heading' => 'The list',
            'items' => [
                ['label' => 'In stock', 'text' => 'Bought and not sold yet.'],
                ['label' => 'Sold', 'text' => 'Sold on to a customer.'],
                ['label' => 'Trade-in on', 'text' => 'A phone a customer handed in as part of paying for a sale (New Sale screen, Add trade-in phone). It is undone only by cancelling that sale, which hands the phone back.'],
                ['label' => 'Cancel', 'text' => 'Undoes a buyback that was a mistake: the phone leaves stock and the payment is reversed. Only for a phone not sold yet.', 'needs' => 'buybacks-cancel'],
            ],
        ],
    ],
];
