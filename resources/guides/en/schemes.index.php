<?php

// Guide: Schemes. Labels match the screen exactly.

return [
    'title' => 'Schemes',
    'summary' => 'Trade schemes like 10 + 1 free, and claiming the free goods back from the company that pays for them.',
    'sections' => [
        [
            'id' => 'schemes',
            'heading' => 'Schemes',
            'intro' => 'The register and order deliveries add the free pieces by themselves while a scheme runs.',
            'items' => [
                [
                    'label' => 'New Scheme',
                    'text' => 'Product, how many to buy, how many free (in pieces), the dates and the company paying for it.',
                ],
                [
                    'label' => 'Paid for by',
                    'text' => 'Leave it as the shop for a scheme you give yourself; it is then not claimed.',
                ],
            ],
        ],
        [
            'id' => 'claims',
            'heading' => 'Claims',
            'intro' => 'Free goods are claimed at the cost they left stock at, invoice by invoice.',
            'items' => [
                [
                    'label' => 'Create Claim',
                    'text' => 'Claims everything not yet claimed from that company, for the dates chosen.',
                ],
                [
                    'label' => 'Claim statement PDF',
                    'text' => 'The invoice-wise statement to send to the company.',
                ],
                [
                    'label' => 'Settled by company',
                    'text' => 'As credit on your account (comes off what you owe them) or as money paid to you.',
                ],
            ],
        ],
    ],
];
