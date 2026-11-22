<?php

// Guide: Prescription Register. Labels match the screen exactly.

return [
    'title' => 'Prescription Register',
    'summary' => 'Every sale of a medicine you marked Prescription only or Controlled, numbered in one series: the date, the medicine and quantity, the patient, and the prescriber or the reason it was an emergency sale. Which medicines are marked is your own choice; no official list is applied.',
    'sections' => [
        [
            'id' => 'filters',
            'heading' => 'Choosing what to see',
            'items' => [
                ['label' => 'From / To', 'text' => 'The sale dates to show.'],
                ['label' => 'Show', 'text' => 'All entries, only Controlled drugs, only Emergency sales (given without a prescriber), or Details missing: sales not made at the register, such as WhatsApp or online orders, still waiting for the prescriber.'],
                ['label' => 'Print', 'text' => 'Prints the register as shown, e.g. for an inspector.'],
            ],
        ],
        [
            'id' => 'entries',
            'heading' => 'The entries',
            'items' => [
                ['label' => 'No.', 'text' => 'The register number. It runs on across the whole shop and is never reused.'],
                ['label' => 'Prescriber', 'text' => 'The doctor and registration number, or Emergency with its reason. To fill in or correct it, open the sale and use Add details or Edit details.'],
                ['label' => 'Sale', 'text' => 'Opens the sale. A cancelled sale stays in the register, marked cancelled, and isn\'t counted in Entries.'],
            ],
        ],
    ],
];
