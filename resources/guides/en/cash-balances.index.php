<?php

// Guide: Cash & Balances. Labels match the screen exactly.

return [
    'title' => 'Cash & Balances',
    'summary' => 'How much is in your cash and in each account (JazzCash, Easypaisa, bank), and what came in and went out each day. It adds up everything the app already records: sales, udhaar, counter services, wallet loads, bills, vendor payments, expenses and payroll.',
    'sections' => [
        [
            'id' => 'start',
            'heading' => 'Getting started',
            'steps' => [
                'On Balances, press Set opening balance on Cash in hand and on each account, and enter what was there at the start of the day.',
                'From then on, every payment you record moves the right balance by itself.',
                'At the end of the day, press Count, then enter the cash you counted or the balance your JazzCash, Easypaisa or bank app shows.',
            ],
        ],
        [
            'id' => 'balances',
            'heading' => 'Balances',
            'items' => [
                ['label' => 'In · Out', 'text' => 'What came in and went out since the opening balance.'],
                ['label' => 'Last count', 'text' => 'Your last count, and whether it was over, short or matched. Any difference is kept as a correction, so the balance matches what you counted.'],
                ['label' => 'Move money', 'text' => 'Cash put into the bank, JazzCash topped up from the bank, cash taken out of an account: money moving between your own cash and accounts.'],
                ['label' => 'Not marked cash or account', 'text' => 'Money that moved without anyone picking Cash or an account, so it isn\'t in any balance. Pick one when you record a payment to keep the balances right.'],
            ],
        ],
        [
            'id' => 'flow',
            'heading' => 'Daily flow',
            'items' => [
                ['label' => 'Dates', 'text' => 'Pick a day or a range. It starts on today.'],
                ['label' => 'Cash came in', 'text' => 'The totals for the dates you picked: cash in and out, money into and out of accounts, and the fees earned on counter services.'],
                ['label' => 'Cash or account', 'text' => 'Each day lists cash and every account with what came in, what went out and the net change. Tap a name to see where it came from: sales, wallet loads, expenses and so on.'],
                ['label' => 'Every entry that day', 'text' => 'Each payment behind the totals.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'Moves & counts',
            'items' => [
                ['label' => 'Cancel', 'text' => 'Undoes a wrong opening, move or count. It stays on the list, marked Cancelled, and stops counting.'],
            ],
        ],
    ],
];
