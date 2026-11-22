<?php

// Guide: Return Items (take goods back from one sale). Labels match the screen exactly.

return [
    'title' => 'Return Items',
    'summary' => 'Take back some or all of what was sold on one sale. Stock goes back on the batch it was sold from, and the return is counted on the day it happens. The sale itself stays as it was.',
    'sections' => [
        [
            'id' => 'what',
            'heading' => 'What\'s coming back?',
            'items' => [
                ['label' => 'Quantity', 'text' => 'How much of each item is coming back, up to what is left to return. Leave the others blank.'],
                ['label' => 'Return everything', 'text' => 'Fills in everything still left on the sale.'],
                ['label' => 'Back to stock', 'text' => 'It goes back on the shelf and can be sold again, at the cost it was sold at.'],
                ['label' => 'Damaged', 'text' => 'It goes back and is written off straight away, so the loss shows under Write-offs.'],
            ],
        ],
        [
            'id' => 'refund',
            'heading' => 'The refund',
            'items' => [
                ['label' => 'Value of what\'s coming back', 'text' => 'What the customer paid for it, after any discount on the line or on the whole bill.'],
                ['label' => 'Taken off what\'s still owed', 'text' => 'If the customer still owes on this sale, the return comes off that first.'],
                ['label' => 'To hand back', 'text' => 'The rest, to give back in money. It shows in Cash & Balances as a refund on the return date.'],
                ['label' => 'Refund From', 'text' => 'Cash or the shop account the money is handed back from.'],
                ['label' => 'Store credit', 'text' => 'Puts the amount on the customer\'s store credit instead, to spend on a later sale. A walk-in customer has to be chosen or added first. Where the shop doesn\'t hand back money, this is the only choice.'],
            ],
        ],
        [
            'id' => 'limits',
            'heading' => 'Limits',
            'intro' => 'The shop\'s owner sets how many days after a sale returns are taken, and whether money is handed back at all, under Settings > Returns. Past that limit only the owner can take goods back, and has to say why. A sale with a return can\'t be edited or deleted until its returns are cancelled.',
        ],
    ],
];
