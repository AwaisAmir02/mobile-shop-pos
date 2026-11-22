<?php

// Guide: Sale receipt (one sale). Labels match the screen exactly.

return [
    'title' => 'Sale receipt',
    'summary' => 'One sale: what was sold, the total, what was paid and what is still due. Straight after a sale it opens with a Sale complete box.',
    'sections' => [
        [
            'id' => 'after-sale',
            'heading' => 'Right after a sale',
            'items' => [
                ['label' => 'Sale complete', 'text' => 'The total, and either Paid in full or how much is still due and from whom.'],
                ['label' => 'New Sale', 'text' => 'Back to the register for the next customer. Pressing Enter does the same.'],
                ['label' => 'Download invoice', 'text' => 'A PDF invoice to print, or to send to the customer on WhatsApp.'],
            ],
        ],
        [
            'id' => 'reading',
            'heading' => 'Reading the receipt',
            'items' => [
                ['label' => 'Served by', 'text' => 'Who made the sale.'],
                ['label' => 'Subtotal, Discount, Total', 'text' => 'The items added up, any discount on the whole bill, and what the customer had to pay.'],
                ['label' => 'Paid', 'text' => 'What has been paid so far. The badge says Paid, Partial or Unpaid.'],
                ['label' => 'Paid by', 'text' => 'Each payment on this sale and how it came in, when that was recorded.'],
                ['label' => 'Download PDF', 'text' => 'The same PDF invoice, at any time.'],
            ],
        ],
        [
            'id' => 'changes',
            'heading' => 'Changing or cancelling a sale',
            'needs' => 'sales-edit-cancel',
            'items' => [
                ['label' => 'Edit', 'text' => 'Opens the sale so you can change its items, quantities or prices. Stock and the customer\'s balance follow the change.'],
                ['label' => 'Delete', 'text' => 'Asks first, then cancels the sale. Stock goes back and the sale stops counting in totals, reports and balances. It stays on the list marked Cancelled, and it can\'t be undone. The reason is optional.'],
            ],
        ],
        [
            'id' => 'returns',
            'heading' => 'Returns',
            'needs' => 'sales-returns',
            'items' => [
                ['label' => 'Return Items', 'text' => 'Opens the screen to take back some or all of this sale. Each return gets its own number (RN-) and is counted on the day it happens.'],
                ['label' => 'Exchange', 'text' => 'Opens New Sale in exchange mode: choose what comes back from this sale, then add what the customer takes instead (a size swap is just picking the other size). What comes back pays for the new items; the customer pays any difference, or gets the leftover back as money or store credit. Saved together as a return and a new sale. Cancelling that return undoes the whole exchange.'],
                ['label' => 'Credit note PDF', 'text' => 'The return\'s own document, to print or send: what came back, what came off the amount owed and what was handed back.'],
                ['label' => 'Cancel return', 'text' => 'Undoes a return made by mistake: the goods leave stock again. Not possible once those goods have been sold again.'],
            ],
        ],
        [
            'id' => 'prescription',
            'heading' => 'Prescription register',
            'intro' => 'Shown when the sale had a Prescription only or Controlled medicine: each one\'s register number, the patient and the prescriber (or the emergency reason). Add details fills them in for a sale not made at the register, such as a WhatsApp order; Edit details corrects them. View prescription photo opens the photo, for signed-in shop users only.',
        ],
        [
            'id' => 'collecting',
            'heading' => 'Collecting what\'s due',
            'intro' => 'To take a later payment on this sale, use Record Payment on the sale\'s row in Sales History, or on the customer\'s Udhaar page.',
        ],
    ],
];
