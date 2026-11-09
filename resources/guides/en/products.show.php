<?php

// Guide: the product page (one product). Labels match the screen exactly.

return [
    'title' => 'Product page',
    'summary' => 'Everything about one product: its price and stock, the batches it came in, and where it was sold.',
    'sections' => [
        [
            'id' => 'buttons',
            'heading' => 'At the top',
            'items' => [
                ['label' => 'Add Stock', 'text' => 'Opens Stock In to record more of this product arriving.', 'needs' => 'stock-ins'],
                ['label' => 'Edit', 'text' => 'Opens the product\'s form on the Products list.'],
            ],
        ],
        [
            'id' => 'overview',
            'heading' => 'Overview',
            'items' => [
                ['label' => 'Selling price', 'text' => 'The price the register charges now. It comes from the batch that sells next.'],
                ['label' => 'In stock', 'text' => 'How many you have.'],
                ['label' => 'Cost price', 'text' => 'What the next batch to sell cost you.', 'needs' => 'sales-view-cost-price'],
                ['label' => 'Sold, last 30 days', 'text' => 'How many were sold in the last 30 days.', 'needs' => 'sales'],
                ['label' => 'Details', 'text' => 'Category, brand, unit, barcode and the rest, as entered on the product.'],
            ],
        ],
        [
            'id' => 'tabs',
            'heading' => 'The other tabs',
            'items' => [
                ['label' => 'Variants', 'text' => 'Only for a product in a size or colour group: every variant with its price and stock. Open jumps to one.', 'feature' => 'variants'],
                ['label' => 'Batches', 'text' => 'Each time this product came in through Stock In: how many are left, the cost and selling price, and whether the vendor has been paid. See all in Stock In History shows older ones.', 'needs' => 'stock-ins'],
                ['label' => 'Expires', 'text' => 'In Batches, the expiry date of each batch. Red means expired with stock still left.', 'feature' => 'expiry_tracking', 'needs' => 'stock-ins'],
                ['label' => 'Sales', 'text' => 'The latest sales of this product.', 'needs' => 'sales'],
            ],
        ],
    ],
];
