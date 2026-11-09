<?php

// Guide: Sales register (New Sale). Labels match the screen exactly.

return [
    'title' => 'New Sale',
    'summary' => 'The sales register. Find the products, build the cart, take the payment and complete the sale. The receipt opens straight after.',
    'sections' => [
        [
            'id' => 'quick-start',
            'heading' => 'Making a sale',
            'steps' => [
                'Type a product name in the search box at the top (F2 jumps there) and tap the product to add it to the cart.',
                ['text' => 'Or scan its barcode with the cursor in the search box. It goes straight into the cart.', 'feature' => 'barcode'],
                'Change the quantity with − and + on the cart line.',
                'Under Payment, pick Cash, Partial or Udhaar.',
                'Press Complete Sale (or F9). Stock goes down and the receipt opens.',
            ],
        ],
        [
            'id' => 'finding',
            'heading' => 'Finding products',
            'items' => [
                ['label' => 'Search products', 'text' => 'Results show up as you type. Esc clears the search.'],
                ['label' => 'Frequent products', 'text' => 'When the cart is empty, your most-sold products show here. Tap one to add it.'],
                ['label' => 'Back to results', 'text' => 'A product that comes in sizes or colours shows its variants first. Tap the one the customer wants, or go back to the results.', 'feature' => 'variants'],
                ['label' => 'Add to Cart', 'text' => 'Products sold by weight or length ask how much first, for example 1.5 kg, then go in the cart.', 'feature' => 'measured_units'],
            ],
        ],
        [
            'id' => 'cart',
            'heading' => 'The cart',
            'items' => [
                ['label' => '− and +', 'text' => 'One less or one more. You can also type the number in the box. You can\'t sell more than you have in stock.'],
                ['label' => 'Price & discount', 'text' => 'Opens two boxes for that line. Price each sells it at a different price this time only. Discount (Rs) takes an amount off the whole line.'],
                ['label' => 'Price overridden', 'text' => 'Shows on a line whose price you changed. Below cost price means you would be selling at a loss.'],
                ['label' => 'ⓘ next to the name', 'text' => 'Shows what the product cost you.', 'needs' => 'sales-view-cost-price'],
                ['label' => 'Expires', 'text' => 'The earliest expiry date of the stock this line sells from. The oldest stock is sold first.', 'feature' => 'expiry_tracking'],
                ['label' => '×', 'text' => 'Takes the line out of the cart.'],
                ['label' => 'Checkout', 'text' => 'On a phone, the total stays at the bottom of the screen. Checkout jumps to the payment part.'],
            ],
        ],
        [
            'id' => 'checkout',
            'heading' => 'Taking payment',
            'items' => [
                ['label' => 'Cash', 'text' => 'The customer pays the full amount now.'],
                ['label' => 'Partial', 'text' => 'The customer pays part now. Type it in Amount Paid Now. The rest stays due, so pick the customer.'],
                ['label' => 'Udhaar', 'text' => 'Nothing is paid now. The whole amount goes on the customer\'s account, so a customer is required.'],
                ['label' => 'Paid by', 'text' => 'How the money came in: Cash or one of your accounts (JazzCash, Easypaisa, bank). + New account adds one. Leave Not recorded if you don\'t keep track.'],
                ['label' => 'Customer', 'text' => 'Leave it on Walk-in for a fully paid sale. + New Customer adds a customer without leaving the sale.'],
                ['label' => 'Due After Sale', 'text' => 'How much will still be owed once the sale is saved.'],
                ['label' => 'Today', 'text' => 'Tap it to record a sale from an earlier day. Future dates aren\'t allowed.'],
                ['label' => '+ Add discount', 'text' => 'A discount on the whole bill (Invoice Discount), taken off the subtotal.'],
                ['label' => 'Complete Sale', 'text' => 'Saves the sale, takes the stock out and opens the receipt.'],
            ],
        ],
        [
            'id' => 'shortcuts',
            'heading' => 'Keyboard shortcuts',
            'items' => [
                ['label' => 'F2', 'text' => 'Jump to the search box, ready to type or scan.'],
                ['label' => 'F9', 'text' => 'Complete the sale.'],
                ['label' => 'Esc', 'text' => 'Clear the search.'],
            ],
        ],
    ],
];
